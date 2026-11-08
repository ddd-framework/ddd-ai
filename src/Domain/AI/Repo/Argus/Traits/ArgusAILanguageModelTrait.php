<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\ModelCalls\ModelCallSettings;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Selection\ModelManagerPolicy;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIApiMode;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MediaItems\GenericMediaItem;
use DDD\Domain\Common\Entities\MediaItems\MediaItem;
use DDD\Domain\Common\Entities\MediaItems\Photo;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Traits\ReflectorTrait;
use Doctrine\DBAL\Exception;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * An Argus repo backed by a language model: resolves model and prompts from {@see ArgusLanguageModel}, asks the
 * application's {@see AIModelCallGateService} whether the call may go out, builds the vendor payload (Chat Completions,
 * OpenAI Responses, Gemini-native, Perplexity), parses the answer, prices the usage from the model catalog and hands it
 * to the application's {@see AIModelCallUsageRecorderService}. A using class supplies the user content, the prompt with
 * its parameters and the mapping of the answer onto itself.
 */
trait ArgusAILanguageModelTrait
{
    use ReflectorTrait, ArgusTrait;

    /** @var string Stable event message for mandated system-prompt refusals. */
    protected const string MANDATED_AI_REFUSAL_LOG_MESSAGE = '[ArgusAILanguageModel] Mandated AI refusal detected';

    /**
     * Fallback HTTP timeout in seconds for every language-model repo (read by
     * {@see \DDD\Domain\Base\Repo\Argus\Traits\ArgusLoadTrait::getArgusSettings()} when the repo's `#[ArgusLoad]`
     * declares no `timeout`): without it Guzzle's timeout 0 waits forever, and a call the caller gave up on keeps
     * running server-side and races later retries. 300 s leaves room for long agent turns and large summarizations;
     * fast single-purpose calls set a tighter `timeout:` on their `#[ArgusLoad]`, which always wins.
     */
    public const float DEFAULT_ARGUS_TIMEOUT = 300;

    /** OpenAI's priority-processing service tier — the "fast mode" (2× the token price; owner decision 2026-10-01). */
    public const string SERVICE_TIER_PRIORITY = 'priority';

    /** @var string|null The service tier the LAST request was sent with (null = the default tier) — read by the cost booking. */
    protected ?string $lastRequestServiceTier = null;

    /**
     * The service tier THIS request asks for, or null for the provider's default. A repo that may run in fast mode
     * (the agent conversation, from its purpose) overrides it; the tier is sent only when the model declares
     * {@see AIModel::$supportsFastMode}.
     */
    protected function resolveServiceTierForRequest(AIModel $aiModel): ?string
    {
        // The class-level flag (`#[ArgusLanguageModel(fastMode: true)]`): the setup-critical classes ask for the
        // priority tier on every call; the agent conversation overrides this with its purpose's fastMode.
        return ($this->getArgusLanguageModelInstance()?->fastMode ?? false) && $aiModel->supportsFastMode ? self::SERVICE_TIER_PRIORITY : null;
    }

    /**
     * The OpenAI-shaped body with `service_tier` added when this request asks for a tier the model supports; remembers
     * the tier for the cost booking.
     *
     * @param array $body the wire body
     * @return array the wire body
     */
    protected function applyServiceTierToRequestBody(array $body, AIModel $aiModel): array
    {
        $serviceTier = $this->resolveServiceTierForRequest($aiModel);
        $this->lastRequestServiceTier = $serviceTier;
        if ($serviceTier !== null) {
            $body['service_tier'] = $serviceTier;
        }
        return $body;
    }

    /** @var ArgusLanguageModel|null */
    protected ?ArgusLanguageModel $argusLanguageModel = null;

    /** @var AIModel|null The resolved model (an application subclass of AIModel at runtime when one is configured) */
    protected ?AIModel $aiModel = null;

    /** @var AIPrompt|null */
    protected ?AIPrompt $aiPrompt = null;

    /** @var AIPrompt|null */
    protected ?AIPrompt $aiSystemPrompt = null;

    /** @var string|null The text generated by the AI */
    protected ?string $aiGeneratedResponse;

    /** @var int The amount of tokens used for the AI service */
    protected int $consumedTokens = 0;

    /** @var MoneyAmount|null The costs for AI Service usage */
    protected ?MoneyAmount $aiCosts = null;

    /** @var int|null Input/prompt tokens from the most recent load (for surfacing onto the conversation message) */
    protected ?int $lastInputTokens = null;

    /** @var int|null Output/completion tokens from the most recent load */
    protected ?int $lastOutputTokens = null;

    /** @var int|null Web search calls billed in the most recent load */
    protected ?int $lastWebSearchCount = null;

    /** @var int|null Prompt tokens served from the provider CACHE this load — OpenRouter `usage.prompt_tokens_details.cached_tokens` (the cache-hit signal). */
    protected ?int $lastCachedInputTokens = null;

    /** @var int|null Prompt tokens WRITTEN to the provider cache this load — OpenRouter `usage.prompt_tokens_details.cache_write_tokens` (explicit-caching models). */
    protected ?int $lastCacheWriteTokens = null;

    /** @var float|null Cache discount in $ this load — OpenRouter `usage.cache_discount` (negative on a cache write, positive on a read). */
    protected ?float $lastCacheDiscount = null;

    /** @var Account|null The account billed for this call ({@see billAICostsTo()}); uninitialized until set */
    protected ?Account $accountBilledForAICosts;

    /**
     * @var Entity|null An entity within the billed account the costs are attributed to ({@see billAICostsTo()});
     *      uninitialized until set
     */
    protected ?Entity $entityWithinAccountBilledForAICosts;

    /** @var float The temperature is a parameter that controls the randomness of the LLM's output. A higher temperature will result in more creative and imaginative text, while a lower temperature will result in more accurate and factual text */
    protected float $temperature = 0.8;

    /** @var AIPrompt Temporary stored AIPromptWithParametersApplied */
    protected AIPrompt $aiPromptWithParametersApplied;

    /** @var string|array Temporary stored userContent */
    protected string|array $userContent;

    /** @var string|null Scope for AI usage logging */
    protected ?string $aiUsageScope = null;

    /** @var array|null HTTP response headers from the Argus API call */
    protected ?array $httpResponseHeaders = null;

    /** @var string|null Why the last load of this instance failed; null after a successful load */
    protected ?string $lastLoadFailureReason = null;

    /**
     * @var string|null Runtime override of the provider-prioritization strategy for THIS egress (an
     * {@see ArgusLanguageModel}::PROVIDER_PRIORITIZATION_STRATEGY_* constant). When set, it WINS over the per-class
     * default declared on the `#[ArgusLanguageModel]` attribute — so a caller (debug/tuning, a CLI flag, a
     * per-conversation knob) can steer OpenRouter provider routing for one egress. Null = use the attribute default.
     */
    protected ?string $providerPrioritizationStrategyOverride = null;

    /**
     * @var array<int, string>|null Debug/console only: pin OpenRouter routing to this provider priority list — the
     * `provider.order` body param + `allow_fallbacks:false` — so a latency or reliability probe can compare ONE provider
     * at a time (e.g. ["deepinfra"] vs ["google-vertex"]). It is `order`, not `only`: a LiteLLM proxy honors
     * `provider.order` and strips `provider.only`. Slugs are OpenRouter provider slugs (lowercase); a wrong slug with
     * `allow_fallbacks:false` yields an OpenRouter "No endpoint" error. Process-scoped: never crosses a Messenger worker
     * boundary. Null = OpenRouter picks the provider. A permanent per-model pin belongs in the catalog
     * (`AIModel::$providerFilters`), not here.
     */
    protected static ?array $providerOrderOverrideStatic = null;

    /**
     * Pins (or clears, with null) the OpenRouter `provider.order` for every egress of this class in THIS process.
     *
     * @param array<int, string>|null $providerSlugs
     */
    public static function forceProviderOrder(?array $providerSlugs): void
    {
        static::$providerOrderOverrideStatic = $providerSlugs;
    }

    /**
     * A new worker job starts without a pinned provider order. Trait static: every class that USES the trait holds
     * its own copy, so a worker's job reset calls this on each of them.
     */
    public static function resetProviderOrderOverrideForNewJob(): void
    {
        self::$providerOrderOverrideStatic = null;
    }

    /**
     * @var bool Guard against a retry loop — set once the fallback-model retry has been attempted. The retry re-runs
     *      {@see getLoadPayloadInternal()}, which turns image parts of `$this->userContent` into data URLs in place;
     *      {@see prepareUserContentForPayload()} accepts already-resolved `url` parts, so the second pass is safe.
     */
    protected bool $aiFallbackAttempted = false;

    /**
     * Getter for the $aiGeneratedResponse property
     * @return string|null
     */
    public function getAiGeneratedResponse(): ?string
    {
        return $this->aiGeneratedResponse ?? null;
    }

    /** @return int|null Input/prompt tokens of the most recent load, or null if unknown */
    public function getLastInputTokens(): ?int
    {
        return $this->lastInputTokens;
    }

    /** @return int|null Output/completion tokens of the most recent load, or null if unknown */
    public function getLastOutputTokens(): ?int
    {
        return $this->lastOutputTokens;
    }

    /** @return int|null Web search calls billed in the most recent load, or null if none */
    public function getLastWebSearchCount(): ?int
    {
        return $this->lastWebSearchCount;
    }

    /** @return int|null Prompt tokens served from the provider cache in the most recent load (cache-hit signal). */
    public function getLastCachedInputTokens(): ?int
    {
        return $this->lastCachedInputTokens;
    }

    /** @return int|null Prompt tokens written to the provider cache in the most recent load. */
    public function getLastCacheWriteTokens(): ?int
    {
        return $this->lastCacheWriteTokens;
    }

    /** @return float|null Cache discount in $ of the most recent load. */
    public function getLastCacheDiscount(): ?float
    {
        return $this->lastCacheDiscount;
    }

    /** @return float|null Cost in USD of the most recent load, or null if unknown */
    /**
     * Why the last load of this instance failed — the transport rejection, the upstream HTTP status and message, or
     * an answer without text — or null when the last load succeeded (or none ran). A load never throws for a failed
     * answer: check this, or `getArgusSettings()->isLoadedSuccessfully`, before using the result.
     */
    public function getLastLoadFailureReason(): ?string
    {
        return $this->lastLoadFailureReason;
    }

    /**
     * The reason of a failed load: the call's rejection, else its non-2xx status, else `$fallbackReason` — followed by
     * the upstream error message when the answer carries one (a gateway may answer an upstream error with HTTP 2xx and
     * an `error` / `message` body).
     */
    protected function describeLoadFailure(
        mixed $callResponseData,
        ?ArgusApiOperation $apiOperation,
        string $fallbackReason
    ): string {
        if ($apiOperation?->rejectionReason !== null) {
            return $apiOperation->rejectionReason;
        }
        $non2xxReason = static::rejectionReasonForNon2xxAnswer($callResponseData, $apiOperation);
        if ($non2xxReason !== null) {
            return $non2xxReason;
        }
        $upstreamMessage = '';
        if (is_object($callResponseData)) {
            $upstreamError = $callResponseData->error ?? $callResponseData->message ?? null;
            if (is_object($upstreamError)) {
                $upstreamError = $upstreamError->message ?? null;
            }
            $upstreamMessage = is_scalar($upstreamError) ? trim((string)$upstreamError) : '';
        }
        return $fallbackReason . ($upstreamMessage !== '' ? ': ' . mb_substr($upstreamMessage, 0, 160) : '');
    }

    public function getLastCostInUSD(): ?float
    {
        return $this->aiCosts?->amount;
    }

    /**
     * Set the scope for AI usage logging
     */
    public function setAIUsageScope(string $scope): void
    {
        $this->aiUsageScope = $scope;
    }

    /**
     * The kind of this call for the Argus transport recording and journal (`ddd-argus` reads it duck-typed): the
     * usage scope; null lets `ddd-argus` fall back to the class short name.
     *
     * @return string|null
     * @throws ReflectionException
     */
    public function getArgusCallKind(): ?string
    {
        return $this->getAIUsageScope();
    }

    /**
     * Get the scope for AI usage logging.
     *
     * Priority:
     * 1. Scope set via setAIUsageScope() (manual override)
     * 2. Scope from #[ArgusLanguageModel] attribute
     *
     * @return string|null
     * @throws ReflectionException
     */
    public function getAIUsageScope(): ?string
    {
        // 1. Manual override via setAIUsageScope()
        if ($this->aiUsageScope !== null) {
            return $this->aiUsageScope;
        }

        // 2. From attribute
        $attr = $this->getArgusLanguageModelInstance();
        return $attr?->aiUsageScope;
    }

    /**
     * Setter for the $aiGeneratedResponse property
     * @param string|null $aiGeneratedResponse
     * @return void
     */
    public function setAiGeneratedResponse(?string $aiGeneratedResponse): void
    {
        $this->aiGeneratedResponse = $aiGeneratedResponse;
    }

    /**
     * @return ArgusLanguageModel|null
     * @throws ReflectionException
     */
    public function getArgusLanguageModelInstance(): ?ArgusLanguageModel
    {
        if ($this->argusLanguageModel ?? null) {
            return $this->argusLanguageModel;
        }
        $this->argusLanguageModel = static::getReflectionClass()->getAttributeInstance(ArgusLanguageModel::class);
        return $this->argusLanguageModel;
    }

    /**
     * The catalog model of this name; throws when the catalog has none. `AIModel::getService()` is the container's ONE
     * shared {@see AIModelsService}, so the `throwErrors` flag is set for this lookup only and put back afterwards — a
     * flag left set would make every later lookup of the process (another repo, the agent's model-override lookup)
     * throw on an unknown or retired name instead of returning null.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException the catalog has no model of this name
     * @throws ReflectionException
     */
    protected function findAIModelByNameOrThrow(string $aiModelName): ?AIModel
    {
        /** @var AIModelsService $aiModelsService */
        $aiModelsService = AIModel::getService();
        $throwErrorsBeforeLookup = $aiModelsService->enableThrowErrors();
        try {
            return $aiModelsService->getAIModelByName($aiModelName);
        } finally {
            $aiModelsService->restoreThrowErrors($throwErrorsBeforeLookup);
        }
    }

    /**
     * The prompt of this name; throws when no prompt file exists. Like {@see findAIModelByNameOrThrow()}, the shared
     * {@see AIPromptsService}'s `throwErrors` flag is set for this lookup only and put back afterwards.
     *
     * @throws NotFoundException no prompt of this name
     */
    protected function findAIPromptByNameOrThrow(string $aiPromptName): ?AIPrompt
    {
        /** @var AIPromptsService $aiPromptsService */
        $aiPromptsService = AIPrompt::getService();
        $throwErrorsBeforeLookup = $aiPromptsService->enableThrowErrors();
        try {
            return $aiPromptsService->getAIPromptByName($aiPromptName);
        } finally {
            $aiPromptsService->restoreThrowErrors($throwErrorsBeforeLookup);
        }
    }

    /**
     * @return float
     * @throws ReflectionException
     */
    public function getTemperature(): float
    {
        $argusLanguageModelInstance = $this->getArgusLanguageModelInstance();
        if ($argusLanguageModelInstance && isset($argusLanguageModelInstance->temperature)) {
            return $argusLanguageModelInstance->temperature;
        }
        return $this->temperature;
    }

    /**
     * @return AIPrompt|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getSystemPrompt(): ?AIPrompt
    {
        if (isset($this->aiSystemPrompt)) {
            return $this->aiSystemPrompt;
        }

        $argusLanguageModelInstance = $this->getArgusLanguageModelInstance();
        if (!$argusLanguageModelInstance) {
            return null;
        }

        $systemPromptName = $argusLanguageModelInstance->systemPromptName;

        if (!$systemPromptName) {
            return null;
        }

        $this->aiSystemPrompt = $this->findAIPromptByNameOrThrow($systemPromptName);

        return $this->aiSystemPrompt;
    }

    /**
     * The model of this call. Resolution order: a model already set on the instance; the attribute's `modelScope`
     * ({@see AIModelsService::getModelForScope()}); its `preferredModelTier` ({@see AIModelsService::selectModel()});
     * its `defaultAIModelName`. A using class may override it, e.g. to pick a larger model for a longer input.
     *
     * @return AIModel|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function getAIModel(): ?AIModel
    {
        if (isset($this->aiModel)) {
            return $this->aiModel;
        }
        $argusInstance = $this->getArgusLanguageModelInstance();
        // a declared modelScope resolves the task-appropriate model centrally, ahead of the tier and the default name
        if (isset($argusInstance->modelScope)
            && $argusInstance->modelScope !== null
            && $argusInstance->modelScope !== '') {
            /** @var AIModelsService $modelManager */
            $modelManager = DDDService::instance()->getService(AIModelsService::class);
            $this->aiModel = $modelManager->getModelForScope($argusInstance->modelScope);
            if ($this->aiModel !== null) {
                return $this->aiModel;
            }
            // scope resolved nothing eligible → fall through to the tier/default paths (defaultAIModelName fallback).
        }
        if (isset($argusInstance->preferredModelTier)
            && $argusInstance->preferredModelTier !== null
            && $argusInstance->preferredModelTier !== '') {
            $policy = new ModelManagerPolicy(
                preferredTier: $argusInstance->preferredModelTier,
                preferredVendor: $argusInstance->preferredModelVendor ?? null,
                maxBlendedCostPer1MUsd: $argusInstance->maxModelBlendedCostPer1MUsd ?? null,
            );
            /** @var AIModelsService $modelManager */
            $modelManager = DDDService::instance()->getService(AIModelsService::class);
            $this->aiModel = $modelManager->selectModel(new ModelSelectionContext($policy));
            return $this->aiModel;
        }
        if (isset($this->getArgusLanguageModelInstance()->defaultAIModelName)) {
            $this->aiModel = $this->findAIModelByNameOrThrow($this->getArgusLanguageModelInstance()->defaultAIModelName);
        }
        return $this->aiModel;
    }

    /**
     * Returns AIPrompt to use, this function can be individualized in Subclasses, e.g. in order to use different prompts
     * based on need
     * @return AIPrompt|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getAIPrompt(): ?AIPrompt
    {
        if (isset($this->aiPrompt)) {
            return $this->aiPrompt;
        }
        if (isset($this->getArgusLanguageModelInstance()->defaultAIPromptName)) {
            $argusLanguageModel = $this->getArgusLanguageModelInstance();
            $this->aiPrompt = $this->findAIPromptByNameOrThrow($argusLanguageModel->defaultAIPromptName);
        }
        return $this->aiPrompt;
    }

    /**
     * @param AIPrompt $aiPrompt
     * @return void
     */
    public function setAIPrompt(AIPrompt $aiPrompt): void
    {
        $this->aiPrompt = $aiPrompt;
    }

    /**
     * @return string|array
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws JsonException
     */
    public function getUserContentCached(): string|array
    {
        if (!isset($this->userContent)) {
            $this->userContent = $this->getUserContent();
        }
        return $this->userContent;
    }

    /**
     * @return AIPrompt
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function getAIPromptWithParametersAppliedCached(): AIPrompt
    {
        if (!isset($this->aiPromptWithParametersApplied)) {
            $this->aiPromptWithParametersApplied = $this->getAIPromptWithParametersApplied();
        }
        return $this->aiPromptWithParametersApplied;
    }

    /**
     * Whether the application's budget check runs for this call: the attribute's
     * {@see ArgusLanguageModel::$enforceBudget} (default true). Infrastructure calls that must not be capped by a
     * customer budget declare `enforceBudget: false`; their usage is still recorded. A using class may override it for
     * a computed decision.
     */
    protected function isAIBudgetEnforcedForOperation(): bool
    {
        return $this->getArgusLanguageModelInstance()?->enforceBudget ?? true;
    }

    /**
     * The estimated cost of this call before it is sent: the task prompt with its parameters and the user content,
     * plus the system prompt when one is declared, priced by the model catalog. Null when no model is resolved.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function estimateCostsOfModelCall(): ?MoneyAmount
    {
        $aiModel = $this->getAIModel();
        if (!$aiModel) {
            return null;
        }
        $this->getUserContentCached();
        // AIModel::getEstimatedCostsForPrompt() takes the prompt by reference: hand it a variable
        $aiPromptWithParametersApplied = $this->getAIPromptWithParametersAppliedCached();
        $totalCosts = $aiModel->getEstimatedCostsForPrompt($aiPromptWithParametersApplied, $this->userContent);
        $systemPrompt = $this->getSystemPrompt();
        if ($systemPrompt) {
            $systemPromptCosts = $aiModel->getEstimatedCostsForPrompt($systemPrompt);
            if ($systemPromptCosts) {
                $totalCosts->add($systemPromptCosts->amount);
            }
        }
        return $totalCosts;
    }

    /**
     * The budget stage of the gates, in this order: the gate decides whether the check runs at all (an application's
     * budget lift answers no here, so nothing below — not even the content build — runs); the user content and the
     * prompt are built (a using class may set the billed account there); the gate refuses a call without a billed
     * account; a call without an applied prompt or a model is refused here; the gate loads what its budget decision
     * reads; the cost is estimated; the gate refuses a call over budget.
     *
     * @throws AiBudgetExceededException
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws JsonException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function refuseModelCallOverBudgetIfEnforced(): void
    {
        if (!$this->isAIBudgetEnforcedForOperation()) {
            return;
        }
        $aiModelCallGateService = $this->getAIModelCallGateService();
        if (!$aiModelCallGateService->needsCostEstimateForBudgetCheck($this->buildAIModelCall())) {
            return;
        }
        $this->getUserContentCached();
        $this->getAIPromptWithParametersAppliedCached();
        $aiModelCall = $this->buildAIModelCall();
        $aiModelCallGateService->refuseModelCallWithoutBilledAccount($aiModelCall);
        if (!(isset($this->aiPromptWithParametersApplied) && $this->aiPromptWithParametersApplied)) {
            throw new InternalErrorException('AI Budget calculations are not possible without an AIPrompt set');
        }
        if (!$this->getAIModel()) {
            throw new InternalErrorException('AI Budget calculations are not possible without an AIModel set');
        }
        $aiModelCallGateService->loadBudgetSettingsOfBilledAccount($aiModelCall);
        $estimatedCosts = $this->estimateCostsOfModelCall();
        $aiModelCallGateService->refuseModelCallOverBudget($aiModelCall, $estimatedCosts);
    }

    /**
     * Sets who is billed for this call: an account, and optionally an entity within it the costs are attributed to
     * (e.g. a project or location of the account). Given only an entity, the account is taken from the entity's
     * `getAccount()` when it has one ({@see getAccountBilledForAICosts()} returns it); with no account at all nothing
     * is set. A later call with only an account replaces the account and keeps the entity set before.
     */
    public function billAICostsTo(
        ?Account $account = null,
        ?Entity $entityWithinAccount = null,
    ): void {
        if (!$account && $entityWithinAccount && method_exists($entityWithinAccount, 'getAccount')) {
            $account = $entityWithinAccount->getAccount();
        }
        if (!$account) {
            return;
        }
        $this->accountBilledForAICosts = $account;
        if ($entityWithinAccount) {
            $this->entityWithinAccountBilledForAICosts = $entityWithinAccount;
        }
    }

    /** The account set on THIS instance by {@see billAICostsTo()}, or null; never a process-wide default. */
    public function getAccountBilledForAICosts(): ?Account
    {
        return $this->accountBilledForAICosts ?? null;
    }

    /** The entity set on THIS instance by {@see billAICostsTo()}, or null; never a process-wide default. */
    public function getEntityWithinAccountBilledForAICosts(): ?Entity
    {
        return $this->entityWithinAccountBilledForAICosts ?? null;
    }

    /**
     * Sets the billed account/entity of THIS instance from what the repo already holds, before the eligibility stage
     * of the gates reads them. That stage runs before the prompt build (where most repos set the billed account), so a
     * repo whose billed scope lives on its own entity overrides this. Default: nothing to set.
     */
    protected function prepareBilledEntitiesBeforeGates(): void
    {
    }

    /**
     * The call as the gate and the recorder see it, built from the current state of this instance. Never stored:
     * see {@see AIModelCall}. The model is the one resolved so far (null before resolution); the prompt variant name is
     * read only for the usage record, inside a try — a failure there must not stop the cost booking.
     *
     * @throws ReflectionException
     */
    protected function buildAIModelCall(bool $forUsageRecording = false): AIModelCall
    {
        $promptVariantName = null;
        $promptVariantNameFailed = false;
        if ($forUsageRecording) {
            try {
                $promptVariantName = $this->getPromptVariantNameForUsageRecording();
            } catch (Throwable $throwable) {
                $promptVariantNameFailed = true;
                error_log("Failed to read the prompt variant name for the AI usage record: {$throwable->getMessage()}");
            }
        }
        return new AIModelCall(
            argusClass: static::class,
            aiUsageScope: $this->getAIUsageScope(),
            aiModel: $forUsageRecording ? $this->getAIModel() : ($this->aiModel ?? null),
            aiPrompt: $forUsageRecording ? $this->getAIPrompt() : ($this->aiPrompt ?? null),
            accountBilledForAICosts: $this->getAccountBilledForAICosts(),
            entityWithinAccountBilledForAICosts: $this->getEntityWithinAccountBilledForAICosts(),
            enforceBudget: $this->isAIBudgetEnforcedForOperation(),
            promptVariantName: $promptVariantName,
            promptVariantNameFailed: $promptVariantNameFailed,
        );
    }

    /** The application's gate for model calls (the module default lets every call through). */
    protected function getAIModelCallGateService(): AIModelCallGateService
    {
        /** @var AIModelCallGateService $aiModelCallGateService */
        $aiModelCallGateService = DDDService::instance()->getService(AIModelCallGateService::class);
        return $aiModelCallGateService;
    }

    /** The application's usage recorder for model calls (the module default records nothing). */
    protected function getAIModelCallUsageRecorderService(): AIModelCallUsageRecorderService
    {
        /** @var AIModelCallUsageRecorderService $aiModelCallUsageRecorderService */
        $aiModelCallUsageRecorderService = DDDService::instance()->getService(AIModelCallUsageRecorderService::class);
        return $aiModelCallUsageRecorderService;
    }

    /**
     * Prices the usage of the answer (the provider-reported cost when given, else the catalog price of the tokens,
     * times the fast-tier factor when the request asked for it), keeps the token counts for the `getLast*()` readers,
     * and hands a priced usage to the application's {@see AIModelCallUsageRecorderService}. An answer without a cost
     * records nothing.
     * @param int|null $inputTokens
     * @param int|null $completionTokens
     * @param MoneyAmount|null $providerReportedCost Provider-reported cost (takes precedence over estimation)
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws Exception
     */
    public function updateCostsForUsage(
        ?int $inputTokens,
        ?int $completionTokens,
        ?MoneyAmount $providerReportedCost = null,
        int $cachedInputTokens = 0,
        int $webSearchCallCount = 0,
        int $cacheWriteTokens = 0,
        ?float $cacheDiscount = null,
    ): void
    {
        // keep the per-call token counts for the getLast*() readers (the cost is $this->aiCosts, set further down)
        $this->lastInputTokens = $inputTokens;
        $this->lastOutputTokens = $completionTokens;
        $this->lastWebSearchCount = $webSearchCallCount;
        $this->lastCachedInputTokens = $cachedInputTokens;
        $this->lastCacheWriteTokens = $cacheWriteTokens;
        $this->lastCacheDiscount = $cacheDiscount;

        $aiModel = $this->getAIModel();
        $pricingVariant = $this->getUsagePricingVariant();
        $estimatedCosts = null;
        if ($inputTokens !== null && $completionTokens !== null) {
            // The written prompt-cache tokens are priced at the catalog's write multiple only on a request that marked
            // its cache entries itself (a lifetime is named); without one they stay at the plain input price.
            $promptCacheWriteTtl = $this->getPromptCacheWriteTtl();
            $estimatedCosts = $aiModel->getEstimatedCostsForTokens(
                $inputTokens,
                $completionTokens,
                $pricingVariant,
                $cachedInputTokens,
                $webSearchCallCount,
                $promptCacheWriteTtl !== null ? $cacheWriteTokens : 0,
                $promptCacheWriteTtl ?? AIModel::PROMPT_CACHE_WRITE_TTL_DEFAULT,
            );
            // A call sent on the fast tier costs the catalog price times the model's factor (OpenAI priority = 2×).
            if ($estimatedCosts !== null && $this->lastRequestServiceTier !== null && ($aiModel->fastModePriceMultiplier ?? null) !== null) {
                $estimatedCosts->amount = $estimatedCosts->amount * (float)$aiModel->fastModePriceMultiplier;
            }
        }

        if ($providerReportedCost && $estimatedCosts) {
            $this->reportUsageCostMismatchIfNeeded(
                estimatedCosts: $estimatedCosts,
                providerReportedCost: $providerReportedCost,
                pricingVariant: $pricingVariant,
                inputTokens: $inputTokens,
                outputTokens: $completionTokens,
                cachedInputTokens: $cachedInputTokens,
                webSearchCallCount: $webSearchCallCount,
            );
        }

        $this->aiCosts = $providerReportedCost ?? $estimatedCosts;
        if (!$this->aiCosts) {
            return;
        }
        $aiModelCallUsage = new AIModelCallUsage(
            inputTokens: $inputTokens,
            outputTokens: $completionTokens,
            cachedInputTokens: $cachedInputTokens,
            cacheWriteTokens: $cacheWriteTokens,
            cacheDiscount: $cacheDiscount,
            webSearchCallCount: $webSearchCallCount,
            costs: $this->aiCosts,
        );
        $this->getAIModelCallUsageRecorderService()->recordModelCallUsage(
            $this->buildAIModelCall(forUsageRecording: true),
            $aiModelCallUsage,
        );
    }

    /**
     * A name of the prompt variant this call used (e.g. an optional prompt extension), recorded with the usage.
     * Default: none. Read only when the usage is recorded; a failure here does not stop the booking.
     */
    protected function getPromptVariantNameForUsageRecording(): ?string
    {
        return null;
    }

    /**
     * The lifetime of the prompt-cache entries this request marked with explicit breakpoints (one of the
     * `AIModel::PROMPT_CACHE_WRITE_TTL_*` values) — it picks the write price. Null = the request marked none, so its
     * cache writes are booked at the plain input price; a repo that sends breakpoints overrides it.
     */
    protected function getPromptCacheWriteTtl(): ?string
    {
        return null;
    }

    /**
     * Override in subclasses to select an internal pricing variant that matches the request payload.
     */
    protected function getUsagePricingVariant(): ?string
    {
        return null;
    }

    /**
     * Override in subclasses when the vendor returns billed cost in the response envelope.
     */
    protected function extractProviderReportedUsageCost(object $responseObject): ?MoneyAmount
    {
        return null;
    }

    protected function reportUsageCostMismatchIfNeeded(
        MoneyAmount $estimatedCosts,
        MoneyAmount $providerReportedCost,
        ?string $pricingVariant,
        ?int $inputTokens,
        ?int $outputTokens,
        int $cachedInputTokens = 0,
        int $webSearchCallCount = 0,
    ): void {
        $difference = abs(($estimatedCosts->amount ?? 0.0) - ($providerReportedCost->amount ?? 0.0));
        if ($difference < 0.0005) {
            return;
        }

        try {
            $aiModel = $this->getAIModel();
            DDDService::instance()->getLogger()->warning('AI usage cost mismatch detected', [
                'model' => $aiModel?->name,
                'vendor' => $aiModel?->vendor,
                'pricingVariant' => $pricingVariant,
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'cachedInputTokens' => $cachedInputTokens,
                'webSearchCallCount' => $webSearchCallCount,
                'estimatedCostInUSD' => $estimatedCosts->amount,
                'providerReportedCostInUSD' => $providerReportedCost->amount,
                'differenceInUSD' => $difference,
            ]);
        } catch (Throwable) {
            // Diagnostics must never break the request path.
        }
    }

    /**
     * @return string|array Returns user content for AIModel
     */
    abstract public function getUserContent(): string|array;

    /**
     * @return AIPrompt Return AIPrompt with applied parameters
     */
    abstract public function getAIPromptWithParametersApplied(): AIPrompt;

    /**
     * The exact request payload (body + headers) built for the LAST egress call — captured for debugging so the CLI
     * can show precisely what was sent to the model (system prompt, full message array, tools, reasoning). Populated
     * at the end of {@see getLoadPayloadInternal()}.
     *
     * @var array<string, mixed>|null
     */
    public ?array $lastRequestPayload = null;

    /**
     * DEBUG: the RAW model response (the proxy's parsed response object), captured in {@see handleLoadResponseInternal()}
     * so a debug snapshot can show what the model ACTUALLY returned — distinguishing "empty" from "a tool call we failed
     * to parse". Mirror of {@see $lastRequestPayload}. Typed `?object`, not `mixed`: a `mixed` DDD property holding an
     * object breaks the framework's property layer; the assignment guards non-objects.
     *
     * @var object|null
     */
    public ?object $lastRawResponse = null;

    /**
     * The gates every model call passes before its body is built (the head of {@see getLoadPayloadInternal()}, kept in
     * one method so a repo that composes its own body runs exactly the same gates), each a stage of the application's
     * {@see AIModelCallGateService}: what the call requires before a model is resolved; the model; the eligibility of
     * the billed scope (after {@see prepareBilledEntitiesBeforeGates()}); the budget
     * ({@see refuseModelCallOverBudgetIfEnforced()}). Returns the resolved model.
     *
     * @throws AiBudgetExceededException
     */
    protected function runModelCallGates(): ?AIModel
    {
        $aiModelCallGateService = $this->getAIModelCallGateService();
        $aiModelCallGateService->refuseModelCallWithoutUsageScopeOrEnvelope($this->buildAIModelCall());
        $aiModel = $this->getAIModel();
        $this->prepareBilledEntitiesBeforeGates();
        $aiModelCallGateService->refuseModelCallForIneligibleBilledScope($this->buildAIModelCall());
        $this->refuseModelCallOverBudgetIfEnforced();
        return $aiModel;
    }

    /**
     * Reads the user content once and turns every image part that still holds a Photo into a data URL. Returns whether
     * the user content is a complete Chat message list (an array of `{role, content}` entries).
     *
     * @throws InternalErrorException an image part without a usable Photo
     */
    protected function prepareUserContentForPayload(): bool
    {
        $userContentHasCompleteFormat = false;

        // --- Normalize/prepare user content (incl. images -> data URLs) ---
        if ($this->getUserContentCached() && is_array($this->userContent)) {
            // Detect "complete" Chat messages format (array of {role, content, ...})
            $first = reset($this->userContent);
            if (is_array($first) && isset($first['role'])) {
                $userContentHasCompleteFormat = true;
            }

            foreach ($this->userContent as $i => $element) {
                // Case A: top-level content-part style: ['type' => 'image_url', 'image_url' => ['image' => Photo|GenericMediaItem]]
                if (isset($element['type']) && $element['type'] === 'image_url') {
                    // An ALREADY-RESOLVED data-URL part (e.g. a tool-result image resolved upstream) carries
                    // 'url' directly and has no Photo to resolve; accept it as-is instead of throwing.
                    if (isset($element['image_url']['url']) && is_string($element['image_url']['url'])) {
                        continue;
                    }
                    if (!isset($element['image_url']['image']) || !($element['image_url']['image'] instanceof Photo || $element['image_url']['image'] instanceof GenericMediaItem || $element['image_url']['image'] instanceof MediaItem)) {
                        throw new InternalErrorException(
                            "User Content of type 'image_url' must contain key 'image' with an instance of Photo or GenericMediaItem"
                        );
                    }
                    /** @var Photo $photo */
                    $photo = $element['image_url']['image'];
                    if (!(isset($photo->mediaItemContent->fileFormat) && $photo->mediaItemContent->getBase64EncodedContent())) {
                        throw new InternalErrorException(
                            "User Content of type 'image_url' must reference a Photo with mediaItemContent.fileFormat and base64EncodedContent filled"
                        );
                    }
                    $element['image_url']['url'] = "data:image/{$photo->mediaItemContent->fileFormat};base64,{$photo->mediaItemContent->getBase64EncodedContent()}";
                    unset($element['image_url']['image']);
                    $this->userContent[$i] = $element;
                    continue;
                }

                // Case B: message format with content array parts
                if (isset($element['content']) && is_array($element['content'])) {
                    foreach ($element['content'] as $j => $part) {
                        if (isset($part['type']) && $part['type'] === 'image_url') {
                            // An ALREADY-RESOLVED data-URL part (e.g. a tool-result image resolved upstream)
                            // carries 'url' directly and has no Photo to resolve; accept it as-is.
                            if (isset($part['image_url']['url']) && is_string($part['image_url']['url'])) {
                                continue;
                            }
                            if (!isset($part['image_url']['image']) || !($part['image_url']['image'] instanceof Photo || $part['image_url']['image'] instanceof GenericMediaItem)) {
                                throw new InternalErrorException(
                                    "Message content of type 'image_url' must contain key 'image' with an instance of Photo or GenericMediaItem"
                                );
                            }
                            /** @var Photo $photo */
                            $photo = $part['image_url']['image'];
                            if (!(isset($photo->mediaItemContent->fileFormat) && $photo->mediaItemContent->getBase64EncodedContent())) {
                                throw new InternalErrorException(
                                    "Message content of type 'image_url' must reference a Photo with mediaItemContent.fileFormat and base64EncodedContent filled"
                                );
                            }
                            $part['image_url']['url'] = "data:image/{$photo->mediaItemContent->fileFormat};base64,{$photo->mediaItemContent->getBase64EncodedContent()}";
                            unset($part['image_url']['image']);
                            $element['content'][$j] = $part;
                        }
                    }
                    $this->userContent[$i] = $element;
                }
            }
        }

        return $userContentHasCompleteFormat;
    }

    protected function getLoadPayloadInternal(): ?array
    {
        $aiModel = $this->runModelCallGates();
        $userContentHasCompleteFormat = $this->prepareUserContentForPayload();

        $argusLanguageModel = method_exists($this, 'getArgusLanguageModelInstance')
            ? $this->getArgusLanguageModelInstance()
            : ($this->argusLanguageModel ?? null);

        // --- Perplexity vendor path ---
        // Minimal payload: no response_format (Sonar doesn't support JSON mode).
        // @todo If additional vendors need custom payload logic, extract vendor strategies
        if ($aiModel->vendor === AIModel::VENDOR_PERPLEXITY && !$this->forcesOpenAIChatCompletionPayload()) {
            $messages = [
                [
                    'role' => 'system',
                    'content' => $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->getUserContentCached(),
                ],
            ];

            $body = [
                'model'       => $this->resolveEgressModelId($aiModel),
                'max_tokens'  => $aiModel->settings->maxOutputTokens,
                'temperature' => $this->getTemperature(),
                'messages'    => $messages,
            ];
            $body = $this->applyServiceTierToRequestBody($body, $aiModel);

            return $this->applyEgressAuthentication(['body' => $body]);
        }

        // --- Google Gemini vendor path --- (never towards an OpenRouter-bound endpoint: OpenRouter takes the Chat
        // Completions shape for every vendor)
        if ($aiModel->vendor === AIModel::VENDOR_GOOGLE && !$this->forcesOpenAIChatCompletionPayload() && !$this->loadEndpointTargetsOpenRouter()) {
            // System instruction (optional)
            $systemPrompt = method_exists($this, 'getSystemPrompt') ? $this->getSystemPrompt() : null;
            $systemInstruction = $systemPrompt ? $systemPrompt->getPromptTextWithParametersApplied() : '';

            // Task-specific prompt that we always lead with
            $taskSpecificPrompt = $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied();

            // Build Gemini "parts" with text + inline image data
            $parts = [];
            if (is_string($taskSpecificPrompt) && $taskSpecificPrompt !== '') {
                $parts[] = ['text' => $taskSpecificPrompt];
            }

            // Accept string content, array of parts, or Chat-style messages
            if (is_string($this->userContent)) {
                $parts[] = ['text' => '<user-data>' . $this->userContent . '</user-data>'];
            } elseif (is_array($this->userContent)) {
                $first = reset($this->userContent);
                // Chat-style messages
                if (is_array($first) && isset($first['role'])) {
                    foreach ($this->userContent as $msg) {
                        $content = $msg['content'] ?? null;
                        if (is_string($content)) {
                            $parts[] = ['text' => $content];
                        } elseif (is_array($content)) {
                            foreach ($content as $part) {
                                if (($part['type'] ?? null) === 'text' && isset($part['text'])) {
                                    $parts[] = ['text' => (string)$part['text']];
                                } elseif (($part['type'] ?? null) === 'image_url' && isset($part['image_url']['url'])) {
                                    $geminiInlineImagePart = $this->buildGeminiInlineImagePart((string)$part['image_url']['url']);
                                    if ($geminiInlineImagePart !== null) {
                                        $parts[] = $geminiInlineImagePart;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // Parts-style array
                    foreach ($this->userContent as $element) {
                        if (($element['type'] ?? null) === 'text' && isset($element['text'])) {
                            $parts[] = ['text' => (string)$element['text']];
                        } elseif (($element['type'] ?? null) === 'image_url' && isset($element['image_url']['url'])) {
                            $geminiInlineImagePart = $this->buildGeminiInlineImagePart((string)$element['image_url']['url']);
                            if ($geminiInlineImagePart !== null) {
                                $parts[] = $geminiInlineImagePart;
                            }
                        }
                    }
                }
            }

            $body = [
                'model' => $this->resolveEgressModelId($aiModel),
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => $parts ?: [['text' => '']],
                    ],
                ],
                'generation_config' => [
                    'temperature'       => $this->getTemperature(),
                    'max_output_tokens' => $aiModel->settings->maxOutputTokens,
                ],
            ];

            // The Gemini gateway schema marks generation_config.system_instruction as
            // required, so it must always be present. Callers without a dedicated system
            // prompt (e.g. a conversation opener that carries its full prompt in the user
            // content) send an empty string, which satisfies the schema.
            $body['generation_config']['system_instruction'] = $systemInstruction;

            $argusLanguageModel = method_exists($this, 'getArgusLanguageModelInstance')
                ? $this->getArgusLanguageModelInstance()
                : ($this->argusLanguageModel ?? null);

            if ($argusLanguageModel
                && $argusLanguageModel->responseFormat === ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT
            ) {
                $body['generation_config']['response_mime_type'] = 'application/json';
            }

            return $this->applyEgressAuthentication(['body' => $body]);
        }

        if ($this->usesOpenAIResponsesApi($aiModel) && !$this->forcesOpenAIChatCompletionPayload()) {
            $body = $this->buildOpenAIResponsesPayload(
                aiModel: $aiModel,
                argusLanguageModel: $argusLanguageModel,
                userContentHasCompleteFormat: $userContentHasCompleteFormat,
            );
            return $this->applyEgressAuthentication(['body' => $body]);
        }

        // --- OpenAI Chat Completions vendor path (default) ---
        $messages = [];
        if ($userContentHasCompleteFormat && is_array($this->userContent)) {
            // Pass through user-provided messages as-is
            $messages = $this->userContent;
        } else {
            // Build minimal system+user messages
            $messages = $this->buildOpenAIChatCompletionSystemAndUserMessages();
        }

        $return = [
            'body' => [
                /* 'api_key' => Config::getEnv('API_OPENAI_KEY'), */
                'model'       => $this->resolveEgressModelId($aiModel),
                'max_tokens'  => $aiModel->settings->maxOutputTokens,
                'temperature' => $this->getTemperature(),
                'messages'    => $messages,
            ],
        ];
        $return['body'] = $this->applyServiceTierToRequestBody($return['body'], $aiModel);

        $argusLanguageModel = method_exists($this, 'getArgusLanguageModelInstance')
            ? $this->getArgusLanguageModelInstance()
            : ($this->argusLanguageModel ?? null);

        // OpenRouter provider routing ({@see resolveOpenRouterProviderPreference()}); only on a LiteLLM/OpenRouter
        // egress (the `provider` field is OpenRouter-specific).
        $providerPreference = $this->resolveOpenRouterProviderPreference($argusLanguageModel, $aiModel);
        if ($providerPreference !== null && ($this->loadEndpointTargetsLiteLLM() || $this->loadEndpointTargetsOpenRouter())) {
            $return['body']['provider'] = $providerPreference;
        }

        if ($argusLanguageModel && $argusLanguageModel->responseFormat !== ArgusLanguageModel::RESPONSE_FORMAT_DEFAULT) {
            if ($argusLanguageModel->responseFormat === ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT) {
                $return['body']['response_format'] = ['type' => 'json_object'];
            }
        }

        if ($argusLanguageModel?->reasoningEffort !== null && $aiModel->isReasoningModel) {
            // OpenRouter wants the unified `reasoning: {effort}` (it maps the effort onto every vendor's own reasoning
            // setting); direct OpenAI Chat Completions wants the native `reasoning_effort`.
            if ($this->loadEndpointTargetsOpenRouter()) {
                $return['body']['reasoning'] = ['effort' => $argusLanguageModel->reasoningEffort];
            } elseif ($aiModel->vendor === AIModel::VENDOR_OPENAI) {
                $return['body']['reasoning_effort'] = $argusLanguageModel->reasoningEffort;
            }
        }

        // Egress auth — the one method every payload shape funnels through; the Perplexity / Gemini / OpenAI-Responses
        // early-return branches above call it too.
        $return = $this->applyEgressAuthentication($return);

        // Function tool-calling on the Chat Completions path: a repo without tools returns [] from
        // getChatCompletionTools() → no `tools` key.
        $chatCompletionTools = $this->getChatCompletionTools();
        if ($chatCompletionTools !== []) {
            $return['body']['tools'] = $chatCompletionTools;
            $chatCompletionToolChoice = $this->getChatCompletionToolChoice();
            if ($chatCompletionToolChoice !== null) {
                $return['body']['tool_choice'] = $chatCompletionToolChoice;
            }
            // Explicit parallel-call opt-in: OpenAI defaults it to true, but some providers cap the model to ONE tool
            // call per response unless the flag is sent. OpenRouter drops the param for providers that do not list it
            // in supported_parameters, so sending it unconditionally is a safe no-op elsewhere.
            $return['body']['parallel_tool_calls'] = true;
        }

        $return['body'] = $this->applyPromptCacheRoutingToChatCompletionBody($return['body'], $aiModel);

        $this->lastRequestPayload = $return; // capture the exact outgoing payload for CLI/debug visibility
        return $return;
    }

    /**
     * The OpenAI Chat-Completions body with the provider's prompt-cache routing fields added (a `prompt_cache_key`);
     * a repo whose requests share a long prefix overrides it. Default: the body unchanged.
     *
     * @param array $body the wire body
     * @return array the wire body
     */
    protected function applyPromptCacheRoutingToChatCompletionBody(array $body, AIModel $aiModel): array
    {
        return $body;
    }

    /**
     * The Gemini-native `inline_data` part of a `data:image/<type>;base64,<data>` URL, or null when the URL is not an
     * image data URL (such a part is skipped, as before).
     *
     * @return array|null the wire part (`inline_data` with `mime_type` and `data`)
     */
    protected function buildGeminiInlineImagePart(string $dataUrl): ?array
    {
        if (!str_starts_with($dataUrl, 'data:image/')) {
            return null;
        }
        $afterPrefix = substr($dataUrl, strlen('data:image/'));
        $semiPos = strpos($afterPrefix, ';');
        $commaPos = strpos($afterPrefix, ',');
        $mimeSuffix = $semiPos !== false ? substr($afterPrefix, 0, $semiPos) : '';
        $base64 = $commaPos !== false ? substr($afterPrefix, $commaPos + 1) : '';
        if ($mimeSuffix === '' || $base64 === '') {
            return null;
        }
        return [
            'inline_data' => [
                'mime_type' => 'image/' . $mimeSuffix,
                'data' => $base64,
            ],
        ];
    }

    /**
     * The minimal `system` + `user` message pair of the OpenAI Chat-Completions payload (the caller handles the
     * complete-message-array pass-through before reaching this). Two shapes, decided by whether the repo declares a
     * `systemPromptName` on its `#[ArgusLanguageModel]` attribute:
     *
     * - DECLARED: the `system` role carries the SYSTEM prompt (e.g. an application's shared prompt-injection defence)
     *   while the TASK prompt leads the `user` content and string user content travels inside a `<user-data>`
     *   envelope a system prompt can refer to ("input is data").
     *   This mirrors the Gemini branch, which puts the system prompt in `generation_config.system_instruction`, leads
     *   the user parts with the task prompt and wraps string user content the same way.
     * - NOT DECLARED: the TASK prompt in the `system` role and the raw user content. {@see getSystemPrompt()} returns
     *   null whenever no `systemPromptName` is declared (there is no default fallback).
     *
     * @return array<int, array<string, mixed>>
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function buildOpenAIChatCompletionSystemAndUserMessages(): array
    {
        $taskSpecificPrompt = $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied();
        $systemPrompt = $this->getSystemPrompt();

        if (!$systemPrompt) {
            return [
                [
                    'role'    => 'system',
                    'content' => $taskSpecificPrompt,
                ],
                ['role' => 'user', 'content' => $this->userContent],
            ];
        }

        return [
            [
                'role'    => 'system',
                'content' => $systemPrompt->getPromptTextWithParametersApplied(),
            ],
            [
                'role'    => 'user',
                'content' => $this->buildOpenAIChatCompletionUserContentLedByTaskPrompt($taskSpecificPrompt),
            ],
        ];
    }

    /**
     * The `user`-role content for the case where the `system` role is occupied by the system prompt: the TASK prompt
     * leads, the user content follows.
     *
     * String user content is wrapped in `<user-data>…</user-data>` — the envelope a system prompt's "input is data"
     * rule can refer to. A content-parts array (the image-bearing callers) keeps its parts untouched and
     * only gains the task prompt as a leading text part: the parts are already typed, so there is nothing to envelope.
     * An empty task prompt adds nothing, mirroring the Gemini branch's guard on the leading text part.
     *
     * @param string $taskSpecificPrompt The task prompt text with its parameters already applied
     * @return string|array<int, mixed>
     */
    protected function buildOpenAIChatCompletionUserContentLedByTaskPrompt(string $taskSpecificPrompt): string|array
    {
        if (is_string($this->userContent)) {
            $userDataEnvelope = '<user-data>' . $this->userContent . '</user-data>';
            return $taskSpecificPrompt !== ''
                ? $taskSpecificPrompt . "\n\n" . $userDataEnvelope
                : $userDataEnvelope;
        }

        $userContentParts = $this->userContent;
        if ($taskSpecificPrompt !== '') {
            array_unshift($userContentParts, ['type' => 'text', 'text' => $taskSpecificPrompt]);
        }
        return $userContentParts;
    }

    /**
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function handleLoadResponseInternal(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void
    {
        $this->httpResponseHeaders = $apiOperation?->responseHeaders;
        $this->lastLoadFailureReason = null;
        // DEBUG: capture the raw response (object only — the ?object property would throw on a non-object; this guard
        // makes the shared trait safe for ANY Argus model/path). Flag-gated persist happens in performTurn.
        $this->lastRawResponse = is_object($callResponseData) ? $callResponseData : null;

        $retryableStatusCodes = [503];
        $statusCode = isset($callResponseData->status_code) ? (int)$callResponseData->status_code : null;
        $argusLanguageModel = $this->getArgusLanguageModelInstance();
        $fallbackModelName = $argusLanguageModel?->fallbackAIModelName;

        if (
            !$this->aiFallbackAttempted
            && $fallbackModelName
            && $statusCode !== null
            && in_array($statusCode, $retryableStatusCodes, true)
        ) {
            $this->aiFallbackAttempted = true;

            try {
                DDDService::instance()->getLogger()->notice(
                    '[ArgusAILanguageModel] Retrying ' . static::class
                    . ' with fallback model ' . $fallbackModelName
                    . ' after status_code ' . $statusCode,
                );

                $fallbackModel = $this->findAIModelByNameOrThrow($fallbackModelName);

                if (!$fallbackModel) {
                    // Constant resolved but model not found in DB. Fall through to failure path.
                    DDDService::instance()->getLogger()->warning(
                        '[ArgusAILanguageModel] Fallback model ' . $fallbackModelName
                        . ' did not resolve for ' . static::class . ' — skipping retry',
                    );
                } elseif (
                    $this->aiModel !== null
                    && $fallbackModel->vendor !== $this->aiModel->vendor
                ) {
                    // Cross-vendor retry not yet supported (endpoint hardcoded per vendor).
                    DDDService::instance()->getLogger()->warning(
                        '[ArgusAILanguageModel] Skipping fallback for ' . static::class
                        . ' — vendor mismatch (current: ' . $this->aiModel->vendor
                        . ', fallback: ' . $fallbackModel->vendor . ')',
                    );
                } else {
                    $this->aiModel = $fallbackModel;
                    // Re-fire the full Argus load pipeline. New payload will be built via
                    // getLoadPayloadInternal() using the swapped $this->aiModel. Bypass
                    // both layers of cache so the previous failed response isn't reused.
                    $this->argusLoad(
                        useArgusEntityCache: false,
                        useApiACallCache: false,
                    );
                    return;
                }
            } catch (Throwable $t) {
                // Any failure during the retry attempt (model resolution, recursive load,
                // budget exhaustion on the fallback, etc.) must not propagate — fall
                // through to the existing failure-handling branch below so the call
                // degrades gracefully.
                /** @var IssuesLogService $logService */
                $logService = DDDService::instance()->getService(IssuesLogService::class);
                $logService->logThrowable($t);
            }
        }

        $responseObject = $this->unwrapAIModelResponse($callResponseData);
        if (!$responseObject) {
            $this->lastLoadFailureReason = $this->describeLoadFailure($callResponseData, $apiOperation, 'no parseable answer');
            $this->postProcessLoadResponse($callResponseData, false);
            // AI loads must not persist a transient failure (e.g. gateway 503) for the
            // full cache TTL — clear the empty entry written by postProcessLoadResponse.
            $this->clearArgusCache();
            return;
        }

        $this->extractVendorSpecificResponseData($responseObject);

        $loadResult = $this->extractOpenAIResponsesText($responseObject);

        // Prefer OpenAI-like structure if present (works for your current Gemini gateway response)
        if ($loadResult === null) {
            $loadResult = $responseObject->choices[0]->message->content ?? null;
        }

        // Fallback to Gemini-native structure if choices/message not available
        if ($loadResult === null && isset($responseObject->candidates[0])) {
            $candidate = $responseObject->candidates[0];
            if (isset($candidate->content->parts) && is_array($candidate->content->parts)) {
                $texts = [];
                foreach ($candidate->content->parts as $part) {
                    if (isset($part->text)) {
                        $texts[] = (string)$part->text;
                    }
                }
                $loadResult = $texts ? implode("\n", $texts) : null;
            }
        }

        // Tokens/usage: support both OpenAI and Gemini usage fields
        $providerReportedCost = $this->extractProviderReportedUsageCost($responseObject);
        $responsesUsage = $this->extractOpenAIResponsesUsage($responseObject);
        if ($responsesUsage !== null) {
            // the written cache tokens are booked like on Chat Completions: priced at the write multiple only when the
            // request named a write lifetime ({@see getPromptCacheWriteTtl()})
            $this->updateCostsForUsage(
                $responsesUsage->inputTokens,
                $responsesUsage->outputTokens,
                $providerReportedCost,
                $responsesUsage->cachedInputTokens,
                $this->countWebSearchCalls($responseObject),
                $responsesUsage->cacheWriteTokens,
            );
        } elseif (isset($responseObject->usage)) {
            $promptTokens = isset($responseObject->usage->prompt_tokens)
                ? (int)$responseObject->usage->prompt_tokens
                : null;
            $completionTokens = isset($responseObject->usage->completion_tokens)
                ? (int)$responseObject->usage->completion_tokens
                : null;
            // OpenRouter cache usage (chat-completions shape): read tokens from cache, written tokens, $ discount.
            // OpenRouter reports the written tokens under `prompt_tokens_details`; the top-level field is the older shape.
            $cachedInputTokens = (int)($responseObject->usage->prompt_tokens_details->cached_tokens ?? 0);
            $cacheWriteTokens = (int)($responseObject->usage->prompt_tokens_details->cache_write_tokens
                ?? $responseObject->usage->cache_write_tokens
                ?? 0);
            $cacheDiscount = isset($responseObject->usage->cache_discount)
                ? (float)$responseObject->usage->cache_discount
                : null;
            $this->updateCostsForUsage(
                $promptTokens,
                $completionTokens,
                $providerReportedCost,
                $cachedInputTokens,
                0,
                $cacheWriteTokens,
                $cacheDiscount,
            );
        } elseif (isset($responseObject->usageMetadata)) {
            $promptTokens = (int)($responseObject->usageMetadata->promptTokenCount ?? 0);
            $candidatesTokens = (int)($responseObject->usageMetadata->candidatesTokenCount ?? 0);
            $this->updateCostsForUsage($promptTokens, $candidatesTokens, $providerReportedCost);
        } elseif ($providerReportedCost) {
            $this->updateCostsForUsage(null, null, $providerReportedCost);
        }

        $this->recordMandatedAIRefusalIfDetected($loadResult);

        // Function tool-calls (OpenAI-form). The agent loop overrides handleToolCalls() to capture them;
        // a pure tool-call turn legitimately carries no assistant text, so it is NOT treated as a failure.
        $toolCalls = $this->extractToolCallsFromResponse($responseObject);
        $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];
        if ($hasToolCalls) {
            $this->handleToolCalls($toolCalls);
        }

        if (!$loadResult) {
            if ($hasToolCalls) {
                $this->postProcessLoadResponse($callResponseData, true);
                return;
            }
            $this->lastLoadFailureReason = $this->describeLoadFailure($callResponseData, $apiOperation, 'an answer without text');
            $this->postProcessLoadResponse($callResponseData, false);
            // AI loads must not persist a transient failure for the full cache TTL.
            $this->clearArgusCache();
            return;
        }

        $this->applyLoadResult($loadResult);
        $this->postProcessLoadResponse($callResponseData, true);
    }

    /**
     * Logs an answer that is the refusal sentence the application's system prompts mandate
     * ({@see ModelCallSettings::getMandatedRefusalPrefix()}). Diagnostic only: parsing, the fallback and the success
     * classification are unchanged.
     *
     * @param mixed $loadResult Assistant text extracted from the provider response
     */
    protected function recordMandatedAIRefusalIfDetected(mixed $loadResult): void
    {
        if (!$this->isMandatedAIRefusal($loadResult)) {
            return;
        }

        try {
            $this->writeMandatedAIRefusalLog(
                self::MANDATED_AI_REFUSAL_LOG_MESSAGE,
                [
                    'repositoryClass' => static::class,
                    'accountId' => $this->resolveAccountIdForMandatedAIRefusalLogging(),
                ],
            );
        } catch (Throwable) {
            // Diagnostics must never interrupt parsing or alter the provider response path.
        }
    }

    /**
     * Whether the assistant text starts with the refusal sentence the application's system prompts mandate
     * ({@see getMandatedRefusalPrefix()}); always false when the application configures none. A repo that parses its
     * answer into a value reads it in its `applyLoadResult()` to tell a refused request from an unusable answer.
     *
     * @param mixed $loadResult Assistant text extracted from the provider response
     */
    protected function isMandatedAIRefusal(mixed $loadResult): bool
    {
        $mandatedRefusalPrefix = $this->getMandatedRefusalPrefix();
        return $mandatedRefusalPrefix !== null && is_string($loadResult) && str_starts_with($loadResult, $mandatedRefusalPrefix);
    }

    /**
     * The refusal sentence the application's system prompts mandate (config `AI.modelCalls.mandatedRefusalPrefix`), or
     * null when the application configures none.
     */
    protected function getMandatedRefusalPrefix(): ?string
    {
        return ModelCallSettings::getSettingsOfThisProcess()->getMandatedRefusalPrefix();
    }

    /**
     * Protected seam for the authenticated account lookup used by mandated-refusal diagnostics.
     */
    protected function resolveAccountIdForMandatedAIRefusalLogging(): ?int
    {
        return AuthService::instance()->getAccount()?->id;
    }

    /**
     * Protected seam for the mandated-refusal logger, kept separate from response parsing for test isolation.
     *
     * @param array $context the PSR-3 log context (repositoryClass, accountId)
     */
    protected function writeMandatedAIRefusalLog(string $message, array $context): void
    {
        DDDService::instance()->getLogger()->warning($message, $context);
    }

    /**
     * The function tool calls of the answer in the OpenAI Chat-Completions form (`choices[0].message.tool_calls`); null
     * when it carries none. A repo that reads another response shape (the agent's Responses-API calls) overrides it.
     *
     * @return array<int, mixed>|null
     */
    protected function extractToolCallsFromResponse(object $responseObject): ?array
    {
        $toolCalls = $responseObject->choices[0]->message->tool_calls ?? null;
        return is_array($toolCalls) ? $toolCalls : null;
    }

    /**
     * Extract vendor-specific data from the validated response object.
     * Called after status validation, before content/usage extraction.
     * Override in subclasses to capture supplementary data (citations, annotations, etc.).
     *
     * @param object $responseObject The validated response data ($callResponseData->data)
     */
    protected function extractVendorSpecificResponseData(object $responseObject): void
    {
        // No-op by default
    }

    /**
     * Override in subclasses to enable Responses-only OpenAI tools such as web search.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getOpenAIResponsesTools(): array
    {
        return [];
    }

    /**
     * Override in subclasses to cap the total number of tool invocations across all tool types.
     * Maps to the OpenAI Responses API `max_tool_calls` parameter.
     * Returns null (no cap) by default.
     *
     * @return int|null Maximum tool calls, or null for no limit
     */
    protected function getOpenAIResponsesMaxToolCalls(): ?int
    {
        return null;
    }

    /**
     * Override in subclasses to expose function tools on the OpenAI-Chat / OpenRouter completion path
     * (e.g. an agent loop). Default [] → no `tools` key.
     *
     * @return array<int, array<string, mixed>> OpenAI-form tool definitions ([{type:'function', function:{…}}])
     */
    protected function getChatCompletionTools(): array
    {
        return [];
    }

    /**
     * Override to set `tool_choice` for the chat-completion tools (e.g. 'auto'). Default null → omitted.
     *
     * @return string|array|null the OpenAI wire form: a mode string ('auto'/'required') or a named-function pin
     *                           array `{"type":"function","function":{"name":"<tool>"}}`
     */
    protected function getChatCompletionToolChoice(): string|array|null
    {
        return null;
    }

    /**
     * Override to force the OpenAI Chat-Completions payload shape regardless of the model's vendor (so it can be
     * routed via the OpenRouter passthrough, which normalises all providers to the OpenAI form). Default false →
     * vendor-based payload selection is unchanged for existing features.
     */
    protected function forcesOpenAIChatCompletionPayload(): bool
    {
        return false;
    }

    /**
     * True when this model's configured load endpoint is OpenRouter-bound WITHOUT a LiteLLM proxy — either the
     * direct `openrouter.ai` endpoint or a gateway passthrough whose path names OpenRouter. Drives the OpenRouter payload
     * conventions shared by both: the BARE model slug, the `provider` routing block, the unified `reasoning` object.
     * The AUTH differs between the two — see {@see loadEndpointTargetsOpenRouterDirect()} and the auth seam.
     */
    protected function loadEndpointTargetsOpenRouter(): bool
    {
        return $this->loadEndpointContains('openrouter');
    }

    /**
     * True when the endpoint is the direct `openrouter.ai` API (no middle hop). openrouter.ai authenticates via the
     * `Authorization: Bearer` header — it does not read an `api_key` body field (that is the gateway-passthrough
     * convention, where the gateway lifts the body key onto the upstream request).
     */
    protected function loadEndpointTargetsOpenRouterDirect(): bool
    {
        return $this->loadEndpointContains('openrouter.ai');
    }

    /**
     * True when this model's configured load endpoint routes through a LiteLLM proxy — its host contains the configured
     * `AI.modelCalls.liteLlmHost`. Always false when the application configures no LiteLLM host.
     */
    protected function loadEndpointTargetsLiteLLM(): bool
    {
        $liteLlmHost = ModelCallSettings::getSettingsOfThisProcess()->getLiteLlmHost();
        return $liteLlmHost !== null && $this->loadEndpointContains($liteLlmHost);
    }

    /**
     * Attaches the egress authentication to the given request payload — the ONE auth seam every vendor/apiMode
     * payload shape funnels through (the vendor-specific branches — Perplexity, Gemini, OpenAI Responses — return
     * early before the chat-completion tail, so each calls this; a shape that skips it ships without auth and the
     * provider answers 401). The env var names come from {@see ModelCallSettings}:
     * - LiteLLM proxy endpoint: the `x-litellm-api-key` header (env `liteLlmApiKeyEnvName`); the proxy authenticates
     *   solely via this header.
     * - Direct `openrouter.ai`: the OpenRouter key (env `openRouterApiKeyEnvName`) as the `Authorization: Bearer`
     *   header — openrouter.ai does not read a body `api_key`.
     * - Another OpenRouter-bound endpoint (a gateway passthrough): the key of env `openRouterPassthroughApiKeyEnvName`
     *   as the body `api_key`, which the gateway lifts onto its upstream request; nothing when no env name is
     *   configured (a gateway that authenticates on its own).
     * A configured key that is missing from the environment fails loud — a silently unauthenticated call just yields a
     * provider 401.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws InternalErrorException
     */
    protected function applyEgressAuthentication(array $payload): array
    {
        $modelCallSettings = ModelCallSettings::getSettingsOfThisProcess();
        if ($this->loadEndpointTargetsLiteLLM()) {
            $liteLlmApiKeyEnvName = $modelCallSettings->getLiteLlmApiKeyEnvName();
            $liteLlmKey = $liteLlmApiKeyEnvName !== null ? Config::getEnv($liteLlmApiKeyEnvName) : null;
            if (!$liteLlmKey) {
                throw new InternalErrorException(
                    'LiteLLM endpoint configured but the ' . ($liteLlmApiKeyEnvName ?? 'AI.modelCalls.liteLlmApiKeyEnvName') . ' env var is missing — endpoint is non-functional.'
                );
            }
            $payload['headers']['x-litellm-api-key'] = (string)$liteLlmKey;
            return $payload;
        }
        if ($this->loadEndpointTargetsOpenRouter()) {
            $openRouterApiKeyEnvName = $this->loadEndpointTargetsOpenRouterDirect()
                ? $modelCallSettings->getOpenRouterApiKeyEnvName()
                : $modelCallSettings->getOpenRouterPassthroughApiKeyEnvName();
            if ($openRouterApiKeyEnvName === null && !$this->loadEndpointTargetsOpenRouterDirect()) {
                return $payload;
            }
            $openRouterKey = $openRouterApiKeyEnvName !== null ? Config::getEnv($openRouterApiKeyEnvName) : null;
            if (!$openRouterKey) {
                throw new InternalErrorException(
                    'OpenRouter endpoint configured but the ' . ($openRouterApiKeyEnvName ?? 'AI.modelCalls.openRouterApiKeyEnvName') . ' env var is missing — endpoint is non-functional.'
                );
            }
            if ($this->loadEndpointTargetsOpenRouterDirect()) {
                $payload['headers']['Authorization'] = 'Bearer ' . $openRouterKey;
            } else {
                $payload['body']['api_key'] = (string)$openRouterKey;
            }
            return $payload;
        }
        return $payload;
    }

    /**
     * Whether the configured load endpoint contains the given needle (case-insensitive). Handles the single-string
     * and array endpoint shapes (`#[ArgusLoad(loadEndpoint: '...')]` / `[..., ['GET:/x', $payload]]`).
     */
    protected function loadEndpointContains(string $needle): bool
    {
        if (!isset($this->argusSettings)) {
            return false;
        }
        $loadEndpoint = $this->argusSettings->getLoadEndpoint();
        $endpoints = is_array($loadEndpoint) ? $loadEndpoint : [$loadEndpoint];
        foreach ($endpoints as $endpoint) {
            // Array entries may be `[endpoint, additionalPayload]` — the endpoint string is the first element.
            $endpointString = is_array($endpoint) ? ($endpoint[0] ?? null) : $endpoint;
            if (is_string($endpointString) && stripos($endpointString, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Unwrap the model response into the parseable object. LiteLLM / OpenRouter return a raw OpenAI chat-completion
     * object (`choices`/`usage`) — not a gateway's `{status, data, status_code}` envelope; pass that straight through.
     * Any other shape goes through the standard envelope unwrap ({@see ArgusTrait::getResponseDataFromArgusResponse()}).
     */
    protected function unwrapAIModelResponse(mixed $callResponseData): mixed
    {
        if (is_object($callResponseData) && isset($callResponseData->choices)) {
            return $callResponseData;
        }
        return $this->getResponseDataFromArgusResponse($callResponseData);
    }

    /**
     * The `model` id to put on every egress request payload (Chat Completions, Perplexity, Gemini-native, OpenAI
     * Responses). On both OpenRouter-bound egresses it is the model's OpenRouter id ({@see AIModel::$openRouterExternalId},
     * falling back to `externalId` only when none is configured), but the namespace differs:
     * - LiteLLM proxy: the provider-prefixed `openrouter/<slug>` — a proxy team grant accepts only that namespace.
     * - OpenRouter (direct or a gateway passthrough forwarding the id verbatim): the bare slug — a prefixed id is
     *   rejected with 400 "not a valid model ID".
     * Direct-provider gateway endpoints keep the provider-native `externalId`. An empty id towards OpenRouter throws.
     *
     * @throws InternalErrorException an OpenRouter-bound egress for a model whose id is empty
     */
    protected function resolveEgressModelId(AIModel $aiModel): string
    {
        if ($this->loadEndpointTargetsLiteLLM() || $this->loadEndpointTargetsOpenRouter()) {
            $openRouterModelId = (string)($aiModel->openRouterExternalId ?? $aiModel->externalId);
            if ($openRouterModelId === '') {
                throw new InternalErrorException("No openRouterExternalId configured for model '{$aiModel->name}'");
            }
            return $this->loadEndpointTargetsLiteLLM() ? 'openrouter/' . $openRouterModelId : $openRouterModelId;
        }
        return $aiModel->externalId;
    }

    /**
     * Override the provider-prioritization strategy for this egress (an {@see ArgusLanguageModel}::
     * PROVIDER_PRIORITIZATION_STRATEGY_* constant), winning over the per-class default declared on the
     * `#[ArgusLanguageModel]` attribute. Pass null to clear the override and fall back to the attribute default.
     */
    public function setProviderPrioritizationStrategy(?string $providerPrioritizationStrategy): void
    {
        $this->providerPrioritizationStrategyOverride = $providerPrioritizationStrategy;
    }

    /**
     * The OpenRouter `provider` routing for this egress, or null for OpenRouter's own routing. Precedence:
     * 1. a process-wide debug pin ({@see forceProviderOrder()}): `order` + `allow_fallbacks:false`, no `sort`;
     * 2. else the strategy `sort` — the runtime override ({@see setProviderPrioritizationStrategy()}) or the
     *    attribute's {@see ArgusLanguageModel::$providerPrioritizationStrategy} — plus, when the model carries catalog
     *    {@see \DDD\Domain\AI\Entities\Models\Settings\AIModelProviderFilters}, their order / ignore /
     *    allow_fallbacks merged on top (a catalog `order` wins over `sort`);
     * 3. else, when nothing above decided an order, the attribute's {@see ArgusLanguageModel::$preferredProviders} as
     *    `order` + `allow_fallbacks:true`.
     * `require_parameters` is deliberately never sent: OpenRouter then demands a provider supporting every parameter of
     * the request (tools, tool_choice, temperature, reasoning, …), and for some models no endpoint qualifies (404).
     *
     * @return array<string, mixed>|null the wire `provider` block
     */
    protected function resolveOpenRouterProviderPreference(
        ?ArgusLanguageModel $argusLanguageModel,
        ?AIModel $aiModel = null
    ): ?array {
        $effectiveStrategy = $this->providerPrioritizationStrategyOverride
            ?? $argusLanguageModel?->providerPrioritizationStrategy;
        $sort = ArgusLanguageModel::mapProviderPrioritizationStrategyToSort($effectiveStrategy);

        $providerPreference = [];
        $consoleOrder = static::$providerOrderOverrideStatic;
        if ($consoleOrder === null && $sort !== null) {
            $providerPreference['sort'] = $sort;
        }
        if ($consoleOrder !== null) {
            $providerPreference['order'] = $consoleOrder;
            $providerPreference['allow_fallbacks'] = false;
        } elseif ($aiModel?->providerFilters?->hasAnyDirective()) {
            $providerPreference = $aiModel->providerFilters->applyToProviderBlock($providerPreference);
        }
        if (!isset($providerPreference['order']) && !empty($argusLanguageModel?->preferredProviders)) {
            $providerPreference['order'] = array_values($argusLanguageModel->preferredProviders);
            $providerPreference['allow_fallbacks'] = true;
        }

        return $providerPreference !== [] ? $providerPreference : null;
    }

    /**
     * Hook invoked from handleLoadResponseInternal with the model's `choices[0].message.tool_calls` (OpenAI form)
     * when present. No-op by default; the agent loop overrides this to capture and execute the tool calls.
     *
     * @param array<int, mixed>|null $toolCalls
     * @return void
     */
    protected function handleToolCalls(?array $toolCalls): void
    {
        // No-op by default
    }

    /**
     * @param AIModel|null $aiModel
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function usesOpenAIResponsesApi(?AIModel $aiModel = null): bool
    {
        $aiModel ??= $this->getAIModel();
        if (!$aiModel || $aiModel->vendor !== AIModel::VENDOR_OPENAI) {
            return false;
        }

        $argusLanguageModel = method_exists($this, 'getArgusLanguageModelInstance')
            ? $this->getArgusLanguageModelInstance()
            : ($this->argusLanguageModel ?? null);

        return $argusLanguageModel?->apiMode === ArgusOpenAIApiMode::RESPONSES;
    }

    /**
     * @param AIModel $aiModel
     * @param ArgusLanguageModel|null $argusLanguageModel
     * @param bool $userContentHasCompleteFormat
     * @return array<string, mixed>
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    protected function buildOpenAIResponsesPayload(
        AIModel $aiModel,
        ?ArgusLanguageModel $argusLanguageModel,
        bool $userContentHasCompleteFormat,
    ): array {
        $body = [
            'model' => $this->resolveEgressModelId($aiModel),
            'input' => $this->buildOpenAIResponsesInput($userContentHasCompleteFormat),
        ];

        $instructions = $this->getAIPromptWithParametersAppliedCached()->getPromptTextWithParametersApplied();
        if ($instructions !== '') {
            $body['instructions'] = $instructions;
        }
        $body = $this->applyServiceTierToRequestBody($body, $aiModel);

        $tools = $this->getOpenAIResponsesTools();
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if ($argusLanguageModel?->reasoningEffort !== null && $aiModel->isReasoningModel) {
            $body['reasoning'] = ['effort' => $argusLanguageModel->reasoningEffort];
        }

        $maxToolCalls = $this->getOpenAIResponsesMaxToolCalls();
        if ($maxToolCalls !== null) {
            $body['max_tool_calls'] = $maxToolCalls;
        }

        return $body;
    }

    /**
     * @return string|array<int, array<string, mixed>>
     */
    protected function buildOpenAIResponsesInput(bool $userContentHasCompleteFormat): string|array
    {
        if (!$userContentHasCompleteFormat && is_string($this->userContent ?? null)) {
            return $this->userContent;
        }

        if ($userContentHasCompleteFormat && is_array($this->userContent)) {
            $messages = [];
            foreach ($this->userContent as $message) {
                $messages[] = [
                    'role' => $message['role'] ?? 'user',
                    'content' => $this->buildOpenAIResponsesMessageContent($message['content'] ?? ''),
                ];
            }
            return $messages;
        }

        return [
            [
                'role' => 'user',
                'content' => $this->buildOpenAIResponsesMessageContent($this->userContent ?? ''),
            ],
        ];
    }

    /**
     * @param string|array $content
     * @return array<int, array<string, mixed>>
     */
    protected function buildOpenAIResponsesMessageContent(string|array $content): array
    {
        if (is_string($content)) {
            return [['type' => 'input_text', 'text' => $content]];
        }

        $contentParts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $contentParts[] = ['type' => 'input_text', 'text' => $part];
                continue;
            }

            if (!is_array($part)) {
                continue;
            }

            $normalizedPart = $this->normalizeOpenAIResponsesContentPart($part);
            if ($normalizedPart !== null) {
                $contentParts[] = $normalizedPart;
            }
        }

        return $contentParts ?: [['type' => 'input_text', 'text' => '']];
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>|null
     */
    protected function normalizeOpenAIResponsesContentPart(array $part): ?array
    {
        $type = $part['type'] ?? null;

        if ($type === 'text' && isset($part['text'])) {
            return ['type' => 'input_text', 'text' => (string)$part['text']];
        }

        if ($type === 'input_text' && isset($part['text'])) {
            return ['type' => 'input_text', 'text' => (string)$part['text']];
        }

        if ($type === 'image_url' && isset($part['image_url']['url'])) {
            return ['type' => 'input_image', 'image_url' => (string)$part['image_url']['url']];
        }

        if ($type === 'input_image' && isset($part['image_url'])) {
            return ['type' => 'input_image', 'image_url' => (string)$part['image_url']];
        }

        return null;
    }

    protected function extractOpenAIResponsesText(object $responseObject): ?string
    {
        $texts = [];

        if (isset($responseObject->output) && is_array($responseObject->output)) {
            foreach ($responseObject->output as $outputItem) {
                if (($outputItem->type ?? null) !== 'message' || !isset($outputItem->content) || !is_array(
                        $outputItem->content,
                    )) {
                    continue;
                }

                foreach ($outputItem->content as $contentItem) {
                    if (($contentItem->type ?? null) === 'output_text' && isset($contentItem->text)) {
                        $texts[] = (string)$contentItem->text;
                    }
                }
            }
        }

        if ($texts !== []) {
            return implode("\n", $texts);
        }

        if (isset($responseObject->output_text) && is_string($responseObject->output_text)) {
            return $responseObject->output_text;
        }

        return null;
    }

    /**
     * The token usage of an OpenAI Responses answer (`usage.input_tokens` / `output_tokens` and their details), unpriced;
     * null when the answer carries no Responses usage.
     */
    protected function extractOpenAIResponsesUsage(object $responseObject): ?AIModelCallUsage
    {
        if (!isset($responseObject->usage)) {
            return null;
        }

        $inputTokens = $responseObject->usage->input_tokens ?? null;
        $outputTokens = $responseObject->usage->output_tokens ?? null;

        if ($inputTokens === null && $outputTokens === null) {
            return null;
        }

        $cachedInputTokens = (int)($responseObject->usage->input_tokens_details->cached_tokens ?? 0);

        return new AIModelCallUsage(
            inputTokens: (int)($inputTokens ?? 0),
            outputTokens: (int)($outputTokens ?? 0),
            cachedInputTokens: $cachedInputTokens,
            // OpenRouter reports the written cache tokens of a Responses call here
            cacheWriteTokens: (int)($responseObject->usage->input_tokens_details->cache_write_tokens ?? 0),
            reasoningOutputTokens: (int)($responseObject->usage->output_tokens_details->reasoning_tokens ?? 0),
        );
    }

    /**
     * Counts the number of web_search_call items in the Responses API output array.
     *
     * @param object $responseObject The validated response data
     * @return int Number of web search calls invoked during the response
     */
    protected function countWebSearchCalls(object $responseObject): int
    {
        if (!isset($responseObject->output) || !is_array($responseObject->output)) {
            return 0;
        }

        $count = 0;
        foreach ($responseObject->output as $outputItem) {
            if (($outputItem->type ?? null) === 'web_search_call') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array<int, object>
     */
    protected function getOpenAIResponsesOutputAnnotations(object $responseObject): array
    {
        $annotations = [];

        if (!isset($responseObject->output) || !is_array($responseObject->output)) {
            return $annotations;
        }

        foreach ($responseObject->output as $outputItem) {
            if (($outputItem->type ?? null) !== 'message' || !isset($outputItem->content) || !is_array(
                    $outputItem->content,
                )) {
                continue;
            }

            foreach ($outputItem->content as $contentItem) {
                if (!isset($contentItem->annotations) || !is_array($contentItem->annotations)) {
                    continue;
                }

                foreach ($contentItem->annotations as $annotation) {
                    if (($annotation->type ?? null) !== 'url_citation') {
                        continue;
                    }

                    $annotations[] = isset($annotation->url_citation) && is_object($annotation->url_citation)
                        ? $annotation->url_citation
                        : $annotation;
                }
            }
        }

        return $annotations;
    }

    /**
     * @return string Removes any ```JSON / HTML etc. markup
     */
    public function removeProgrammingLanguageMarkup(string $input): string
    {
        // Regular expression to match and optionally remove Markdown code blocks with ```html or other languages
        $pattern = '/```[a-zA-Z]+\n?(.*?)```/s';

        // Replace the matched pattern with just the content inside the code block
        $cleanedText = preg_replace($pattern, '$1', $input);

        // Remove trailing commas before closing brackets in JSON objects and arrays
        /** @noinspection RegExpRedundantEscape -- \] kept explicit; relying on PCRE's leading-`]`-is-literal rule is a maintenance footgun if the class is later edited */
        $cleanedText = preg_replace('/,\s*([\]}])/', '$1', $cleanedText);

        return $cleanedText;
    }

    /**
     * @return string Replaces Unicode line (U+2028) and paragraph (U+2029) separators occasionally emitted by LLMs with regular newlines
     */
    public function normalizeUnicodeLineSeparators(string $input): string
    {
        return str_replace(["\u{2028}", "\u{2029}"], "\n", $input);
    }

    abstract protected function applyLoadResult(string $resultText): void;
}
