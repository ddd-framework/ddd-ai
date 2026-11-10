<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\AIModels;
use DDD\Domain\AI\Entities\Models\Benchmarks\AIModelBenchmark;
use DDD\Domain\AI\Entities\Models\Benchmarks\AIModelBenchmarks;
use DDD\Domain\AI\Entities\Models\Settings\AIImageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AIModelAgenticUseCaseConfig;
use DDD\Domain\AI\Entities\Models\Settings\AIModelProviderFilters;
use DDD\Domain\AI\Entities\Models\Settings\PromptCacheWritePrice;
use DDD\Domain\AI\Entities\Models\Settings\PromptCacheWritePrices;
use DDD\Domain\AI\Entities\Models\Settings\RequestFeeVariant;
use DDD\Domain\AI\Entities\Models\Settings\RequestFeeVariants;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel;
use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModels;
use DDD\Domain\AI\Entities\Models\Selection\ModelCandidate;
use DDD\Domain\AI\Entities\Models\Selection\ModelManagerPolicy;
use DDD\Domain\AI\Entities\Models\Selection\ModelObjective;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionDecision;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Domain\AI\Entities\Models\Speed\AIModelSpeedMeasurement;
use DDD\Domain\AI\Entities\Models\Speed\AIModelSpeedMeasurements;
use DDD\Domain\AI\Entities\Models\Speed\SpeedPercentiles;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\DDDBundle;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Throwable;

class AIModelsService extends Service
{
    public function getAIModels(): AIModels
    {
        $aiModelsConfig = Config::get('AI.models');
        $entiySetClassName = DDDService::instance()->getContainerServiceClassNameForClass(AIModels::class);
        /** @var AIModels $aiModels */
        $aiModels = new $entiySetClassName();

        foreach ($aiModelsConfig as $modelKey => $modelConfig) {
            $model = $this->createAIModelFromConfig($modelKey, $modelConfig);
            $aiModels->add($model);
        }

        return $aiModels;
    }

    protected function createAIModelFromConfig(string $modelKey, array $modelConfig): AIModel
    {
        $entiyClassName = DDDService::instance()->getContainerServiceClassNameForClass(AIModel::class);
        /** @var AIModel $aiModel */
        $aiModel = new $entiyClassName();
        // Defensive against an incomplete config entry: every required string defaults rather than fataling on a
        // missing key, so ONE malformed/partial model never breaks the whole catalog load. An entry without a `type`
        // simply falls through the LANGUAGE/IMAGE branches below (no settings) and is skipped by the candidate loader.
        $aiModel->type = (string)($modelConfig['type'] ?? '');
        $aiModel->vendor = (string)($modelConfig['vendor'] ?? '');
        $aiModel->name = $modelKey;
        $aiModel->externalId = (string)($modelConfig['externalId'] ?? '');
        $aiModel->openRouterExternalId = $modelConfig['openRouterExternalId'] ?? null;
        $aiModel->description = $modelConfig['description'] ?? null;
        $aiModel->isReasoningModel = (bool)($modelConfig['isReasoningModel'] ?? false);
        $aiModel->hasVisionCapabilities = (bool)($modelConfig['hasVisionCapabilities'] ?? false);
        $aiModel->supportsNativeToolCalling = (bool)($modelConfig['supportsNativeToolCalling'] ?? true);
        $aiModel->supportsFastMode = (bool)($modelConfig['supportsFastMode'] ?? false);
        $aiModel->fastModePriceMultiplier = isset($modelConfig['fastModePriceMultiplier'])
            ? (float)$modelConfig['fastModePriceMultiplier']
            : null;
        $aiModel->agentTier = isset($modelConfig['agentTier']) ? (string)$modelConfig['agentTier'] : null;
        $aiModel->agentEligible = (bool)($modelConfig['agentEligible'] ?? true);
        // Context window: explicit override, else the model's own settings.maxInputTokens / maxTokens (every language
        // model already carries it) — so EVERY model self-describes its window without a redundant per-entry copy.
        $settingsConfigForWindow = $modelConfig['settings'] ?? [];
        $windowFromSettings = isset($settingsConfigForWindow['maxInputTokens'])
            ? (int)$settingsConfigForWindow['maxInputTokens']
            : (isset($settingsConfigForWindow['maxTokens']) ? (int)$settingsConfigForWindow['maxTokens'] : null);
        $aiModel->contextWindowTokens = isset($modelConfig['contextWindowTokens'])
            ? (int)$modelConfig['contextWindowTokens']
            : $windowFromSettings;
        // Compaction threshold: explicit override, else derived from the window class (the reliable-reasoning length),
        // capped at 80% of the window. So every model has a sane threshold.
        $aiModel->effectiveContextTokens = isset($modelConfig['effectiveContextTokens'])
            ? (int)$modelConfig['effectiveContextTokens']
            : self::defaultEffectiveContextTokensForWindow($aiModel->contextWindowTokens);

        // Per-model AGENTIC-use-case config (reasoning effort, temperature) — applied only by the agentic egress,
        // riding with this service's dynamic model selection. Absent → null (provider defaults). Scoped to the agent
        // loop; does not affect other use cases of the same model.
        $agenticConfig = $modelConfig['agenticUseCase'] ?? null;
        if (is_array($agenticConfig) && $agenticConfig !== []) {
            $aiModel->agenticUseCase = new AIModelAgenticUseCaseConfig(
                isset($agenticConfig['reasoningEffort']) ? (string)$agenticConfig['reasoningEffort'] : null,
                isset($agenticConfig['temperature']) ? (float)$agenticConfig['temperature'] : null,
            );
        }

        // Per-model OpenRouter provider-routing filter (order / ignore / allow_fallbacks) — pins a model to a specific
        // upstream provider when it leaks/misbehaves on another (e.g. Qwen3-235B → DeepInfra). Applied to the provider
        // block on the OpenRouter/LiteLLM egress. Absent → OpenRouter picks freely. See {@see AIModelProviderFilters}.
        $providerFiltersConfig = $modelConfig['providerFilters'] ?? null;
        if (is_array($providerFiltersConfig) && $providerFiltersConfig !== []) {
            $aiModel->providerFilters = new AIModelProviderFilters(
                array_values(array_map('strval', (array)($providerFiltersConfig['order'] ?? []))),
                array_values(array_map('strval', (array)($providerFiltersConfig['ignore'] ?? []))),
                isset($providerFiltersConfig['allowFallbacks']) ? (bool)$providerFiltersConfig['allowFallbacks'] : null,
            );
        }

        // Optional agentic-benchmark evidence, parallel to settings (BFCL, τ-bench, GAIA, …). Each entry is a
        // {benchmark, score 0–100, sourceUrl, asOf} datapoint; the blended agentic-intelligence score is computed
        // by AIModelBenchmarks::getAgenticIntelligenceScore(). Absent → null (unrated), no behaviour change.
        $benchmarksConfig = $modelConfig['benchmarks'] ?? null;
        if (is_array($benchmarksConfig) && $benchmarksConfig !== []) {
            $benchmarks = new AIModelBenchmarks();
            foreach ($benchmarksConfig as $entry) {
                if (!is_array($entry) || !isset($entry['benchmark'])) {
                    continue;
                }
                // Assign to a variable first — ObjectSet::add() takes its argument by reference, so passing a
                // `new ...` expression directly raises "Only variables should be passed by reference" (mirrors the
                // RequestFeeVariants hydration pattern below).
                $benchmark = new AIModelBenchmark(
                    (string)$entry['benchmark'],
                    (float)($entry['score'] ?? 0.0),
                    isset($entry['sourceUrl']) ? (string)$entry['sourceUrl'] : null,
                    isset($entry['asOf']) ? (string)$entry['asOf'] : null,
                    (bool)($entry['official'] ?? true),
                );
                $benchmarks->add($benchmark);
            }
            $aiModel->benchmarks = $benchmarks;
        }

        // Optional output-speed evidence, parallel to settings/benchmarks. Each entry is a
        // {source, tokensPerSecond, timeToFirstTokenMs, sourceUrl, asOf} datapoint; the canonical (OpenRouter)
        // tokens/sec and the normalised speed score are exposed via AIModelSpeedMeasurements. Absent → null.
        $speedConfig = $modelConfig['speed'] ?? null;
        if (is_array($speedConfig) && $speedConfig !== []) {
            $speed = new AIModelSpeedMeasurements();
            foreach ($speedConfig as $entry) {
                if (!is_array($entry) || !isset($entry['source'])) {
                    continue;
                }
                // The OPENROUTER_PROVIDERS source carries the per-provider distribution (top/avg × throughput/latency
                // percentiles). Its headline tokensPerSecond / timeToFirstTokenMs is the TOP provider's p50 — the
                // speed-routed expectation — so the canonical speed/score machinery picks the routed value, not a blend.
                $throughputTop = SpeedPercentiles::fromConfigArray($entry['throughputTop'] ?? null);
                $latencyTop = SpeedPercentiles::fromConfigArray($entry['latencyTop'] ?? null);
                $headlineTokensPerSecond = isset($entry['tokensPerSecond'])
                    ? (float)$entry['tokensPerSecond']
                    : ($throughputTop?->p50 ?? 0.0);
                $headlineTimeToFirstTokenMs = isset($entry['timeToFirstTokenMs'])
                    ? (int)$entry['timeToFirstTokenMs']
                    : ($latencyTop?->p50 !== null ? (int)round($latencyTop->p50) : null);
                // Variable first — ObjectSet::add() is by-reference (see the benchmark block above).
                $measurement = new AIModelSpeedMeasurement(
                    (string)$entry['source'],
                    $headlineTokensPerSecond,
                    $headlineTimeToFirstTokenMs,
                    isset($entry['sourceUrl']) ? (string)$entry['sourceUrl'] : null,
                    isset($entry['asOf']) ? (string)$entry['asOf'] : null,
                    isset($entry['providerCount']) ? (int)$entry['providerCount'] : null,
                    isset($entry['topThroughputProvider']) ? (string)$entry['topThroughputProvider'] : null,
                    isset($entry['topLatencyProvider']) ? (string)$entry['topLatencyProvider'] : null,
                    $throughputTop,
                    SpeedPercentiles::fromConfigArray($entry['throughputAvg'] ?? null),
                    $latencyTop,
                    SpeedPercentiles::fromConfigArray($entry['latencyAvg'] ?? null),
                );
                $speed->add($measurement);
            }
            $aiModel->speed = $speed;
        }

        if ($aiModel->type === AIModel::TYPE_LANGUAGE) {
            $settings = $modelConfig['settings'] ?? [];
            $aiModelSetting = new AILanguageModelSetting();

            $aiModelSetting->maxTokens = (int)($settings['maxTokens'] ?? 0);
            $aiModelSetting->maxInputTokens = (int)($settings['maxInputTokens'] ?? $aiModelSetting->maxTokens);
            $aiModelSetting->maxOutputTokens = (int)($settings['maxOutputTokens'] ?? 0);
            $aiModelSetting->maxPracticallyUsableInputTokens = (int)($settings['maxPracticallyUsableInputTokens'] ?? $aiModelSetting->maxInputTokens);

            $aiModelSetting->costsPer1000KInputTokens = new MoneyAmount(
                (float)($settings['costsPer1000InputTokensInUSD'] ?? 0.0), 'USD'
            );
            $aiModelSetting->costsPer1000KOutputTokens = new MoneyAmount(
                (float)($settings['costsPer1000OuputTokensInUSD'] ?? 0.0), 'USD'
            );

            if (isset($settings['inputTierThresholdTokens'])) {
                $aiModelSetting->inputTierThresholdTokens = (int)$settings['inputTierThresholdTokens'];
            }

            if (isset($settings['costsPer1000InputTokensInUSDAboveThreshold'])) {
                $aiModelSetting->costsPer1000InputTokensAboveThreshold = new MoneyAmount(
                    (float)$settings['costsPer1000InputTokensInUSDAboveThreshold'], 'USD'
                );
            }

            if (isset($settings['costsPer1000OutputTokensInUSDAboveThreshold'])) {
                $aiModelSetting->costsPer1000OutputTokensAboveThreshold = new MoneyAmount(
                    (float)$settings['costsPer1000OutputTokensInUSDAboveThreshold'], 'USD'
                );
            }

            if (isset($settings['costsPer1000CachedInputTokensInUSD'])) {
                $aiModelSetting->costsPer1000KCachedInputTokens = new MoneyAmount(
                    (float)$settings['costsPer1000CachedInputTokensInUSD'], 'USD'
                );
            }

            if (isset($settings['costsPerWebSearchCallInUSD'])) {
                $aiModelSetting->costsPerWebSearchCall = new MoneyAmount(
                    (float)$settings['costsPerWebSearchCallInUSD'], 'USD'
                );
            }

            $costsPerRequestInUSDByVariant = $settings['costsPerRequestInUSDByVariant'] ?? null;
            if (is_array($costsPerRequestInUSDByVariant) && $costsPerRequestInUSDByVariant !== []) {
                $aiModelSetting->costsPerRequestVariants = new RequestFeeVariants();
                foreach ($costsPerRequestInUSDByVariant as $variant => $amount) {
                    $feeVariant = new RequestFeeVariant(
                        (string)$variant,
                        new MoneyAmount((float)$amount, 'USD')
                    );
                    $aiModelSetting->costsPerRequestVariants->add($feeVariant);
                }
            }

            if (isset($settings['promptCacheMode'])) {
                $aiModelSetting->promptCacheMode = (string)$settings['promptCacheMode'];
            }

            if (isset($settings['agentWireApi'])) {
                $aiModelSetting->agentWireApi = (string)$settings['agentWireApi'];
            }

            if (isset($settings['agentReasoningContext'])) {
                $aiModelSetting->agentReasoningContext = (string)$settings['agentReasoningContext'];
            }

            $promptCacheWritePriceMultipliers = $settings['promptCacheWritePriceMultipliers'] ?? null;
            if (is_array($promptCacheWritePriceMultipliers) && $promptCacheWritePriceMultipliers !== []) {
                $aiModelSetting->promptCacheWritePrices = new PromptCacheWritePrices();
                foreach ($promptCacheWritePriceMultipliers as $ttl => $priceMultiplier) {
                    $promptCacheWritePrice = new PromptCacheWritePrice((string)$ttl, (float)$priceMultiplier);
                    $aiModelSetting->promptCacheWritePrices->add($promptCacheWritePrice);
                }
            }

            $aiModel->settings = $aiModelSetting;
        } elseif ($aiModel->type === AIModel::TYPE_IMAGE) {
            $settings = $modelConfig['settings'] ?? [];
            $aiModelSetting = new AIImageModelSetting();

            if (isset($settings['costsPerImageInUSD'])) {
                $aiModelSetting->costsPerImageInUSD = new MoneyAmount(
                    (float)$settings['costsPerImageInUSD'], 'USD'
                );
            }

            $aiModel->settings = $aiModelSetting;
        }

        return $aiModel;
    }

    /**
     * Returns AIModel by name
     * @param string $aiModelName
     * @return AIModel|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function getAIModelByName(string $aiModelName): ?AIModel
    {
        $configs = Config::get('AI.models');
        $aiModelConfig = $configs[$aiModelName] ?? null;
        // A name that left the catalog resolves to its successor, so a stored row (a conversation's model override,
        // a message, a usage log) stays readable after a model is retired. The result is MARKED — see
        // AIModel::$resolvedFromRetiredModelName — so a record of what ran keeps naming the model that ran.
        $retiredModelName = null;
        if (!$aiModelConfig && isset(AIModel::RETIRED_MODEL_SUCCESSORS[$aiModelName])) {
            $retiredModelName = $aiModelName;
            $aiModelName = AIModel::RETIRED_MODEL_SUCCESSORS[$aiModelName];
            $aiModelConfig = $configs[$aiModelName] ?? null;
        }
        $aiModel = $aiModelConfig ? $this->createAIModelFromConfig($aiModelName, $aiModelConfig) : null;
        if ($aiModel && $retiredModelName !== null) {
            $aiModel->resolvedFromRetiredModelName = $retiredModelName;
        }

        if ($this->throwErrors && !$aiModel) {
            throw new NotFoundException('AiModel not found');
        }

        return $aiModel;
    }

    // ==== Model selection ====================================================================
    // Picks the model for a {@see ModelManagerPolicy} / {@see ModelScope} from the catalog using the curated
    // per-model `agentTier` flag, the call's hard constraints (tool-calling, cost ceiling, interactive speed,
    // context window) and a price/performance/speed Pareto ranking. The pure pipeline ({@see selectCandidate()})
    // operates on {@see ModelCandidate} projections so it is unit-testable against a fixed snapshot.

    /** @var float Default input weight in the blended cost (agentic workloads are input-heavy). Tunable. */
    public const float BLENDED_INPUT_WEIGHT = 0.7;

    /** @var float Default output weight in the blended cost. */
    public const float BLENDED_OUTPUT_WEIGHT = 0.3;

    /**
     * @var float The "cheap" boundary for the FAST_CHEAP scope (blended $/1M tokens), used INSTEAD of agent-tier
     *      membership since FAST_CHEAP selects across the whole catalog. Generous enough to admit every genuinely
     *      cheap fast generator (gpt-oss-120b/20b, the *-nano/-mini families, MiniMax, Qwen — all well under $1),
     *      while excluding the premium agentic models (GPT-5.x, Opus, Sonnet) whose throughput could otherwise win
     *      the SPEED ranking at a cost FAST_CHEAP must never pay. Tunable.
     */
    public const float FAST_CHEAP_MAX_BLENDED_COST_PER_1M_USD = 2.0;

    protected ?ModelSelectionDecision $lastDecision = null;

    /**
     * Per-process selection cache: {@see ModelSelectionContext::uniqueKey()} → the resolved {@see ModelSelectionDecision}.
     * The catalog + scoring are stable within a run, so the same context (scope + policy + constraints) always resolves
     * to the same model — cache the (expensive) candidate scan/scoring once per distinct context (owner directive).
     * Static so it is shared across all selections in the process; reset via {@see self::clearSelectionCache()}.
     *
     * @var array<string, ModelSelectionDecision>
     */
    protected static array $selectionCache = [];

    /**
     * Scope → PINNED default model (TIER-INDEPENDENT). A scope in this map is NOT resolved by the price-tier heuristic
     * in {@see buildScopeContext()}; its default is a NAMED model chosen on merit (an eval gate), because the scope is
     * its own eligibility dimension, not a price tier. ORCHESTRATOR (an agent supervisor): MiniMax M3 — the
     * supervisor-eval-gate winner (0% wrong-decision at the lowest cost). A consumer overrides it per call (the agent
     * supervisor's per-conversation `orchestratorModelNameOverride`). The scope's `buildScopeContext` tuple is only the
     * FALLBACK, used when the pinned model is absent/removed from the catalog.
     *
     * @var array<string, string>
     */
    protected const array SCOPE_PINNED_DEFAULT_MODELS = [
        ModelScope::ORCHESTRATOR => AIModel::MODEL_MINIMAX_M3,
        // AGENTIC pinned to GPT-5.6 Luna on 2026-08-12 (owner decision). Pinned rather than left to the CHEAP-band
        // heuristic because that heuristic ranks on `blendedCostPer1MUsd`, which is blind to cached input and
        // therefore ranks Luna ABOVE Qwen3-235B on cost ($0.250 vs $0.093 blended) while the measured reality is the
        // exact opposite: $0.0006 vs $0.0040 per warm turn. Our agent load is 99.2% input at a ~79% cache-hit rate
        // and DeepInfra publishes no cached-input price for Qwen, so the blended metric inverts the true ranking.
        // Until the cost model accounts for cached input, an explicit pin is the honest way to express the decision.
        // Moved to GPT-6 Luna on 2026-09-24 (owner decision, release 2026-09-22): same envelope and reasoning family,
        // same input/cached price on OpenRouter, cheaper output; 5.6 Luna stays in the catalog without a tier.
        ModelScope::AGENTIC => AIModel::MODEL_OPENAI_GPT6_LUNA,
        // COMPACTION pinned to Luna on 2026-08-13 (owner decision): the COST-band heuristic selected
        // Qwen3-235B, which intermittently returns an EMPTY summary — a silent compaction stall (the leaf pass
        // stops, the range stays uncovered until a later round). Luna is reliable on the summarize shape and its
        // real per-call cost beats Qwen's under the cached-input reality documented for the AGENTIC pin above.
        ModelScope::COMPACTION => AIModel::MODEL_OPENAI_GPT6_LUNA,
    ];

    /**
     * The model for a {@see ModelScope} (AGENTIC / COMPACTION / MEMORY_MANAGEMENT / FAST_CHEAP) — the central,
     * scope-aware entry the features call instead of hardcoding a model. Maps the scope to its selection policy and
     * resolves the eligible model in that band, cached by context.
     *
     * @throws BadRequestException when no model is eligible for the scope's tier.
     */
    public function getModelForScope(string $scope, ?int $requiredInputTokens = null): ?AIModel
    {
        // A pinned, tier-independent default wins over the tier heuristic (the orchestrator scope is not a price tier).
        $pinnedDefaultModelName = self::SCOPE_PINNED_DEFAULT_MODELS[$scope] ?? null;
        if ($pinnedDefaultModelName !== null) {
            $pinnedModel = $this->getAIModelByName($pinnedDefaultModelName);
            if ($pinnedModel !== null) {
                return $pinnedModel;
            }
        }
        return $this->selectModel($this->buildScopeContext($scope, $requiredInputTokens));
    }

    /**
     * The selection context for a scope: each scope's priorities over (quality score, speed, cost) + capability
     * requirements as a {@see ModelManagerPolicy} band. AGENTIC = CHEAP + interactive + CAPABILITY (the live agent);
     * COMPACTION = CHEAP + non-interactive + COST over the agent-tier band (async background, cost-dominant);
     * MEMORY_MANAGEMENT = CHEAP + non-interactive + CAPABILITY over the agent-vetted CHEAP band (async background, but
     * classification QUALITY-dominant — extract type/scope + reconcile decisions need the smartest cheap model, not raw
     * throughput); FAST_CHEAP = the non-agentic, no-tool-calling SPEED pick over the WHOLE cheap catalog under the cost
     * ceiling (its best model, e.g. gpt-oss-120b, sits outside the agent-CHEAP band).
     */
    protected function buildScopeContext(string $scope, ?int $requiredInputTokens): ModelSelectionContext
    {
        // [tier, interactive, objective, requiresToolCalling, appliesAgentLoopClassification]. FAST_CHEAP does NOT use
        // the agent-loop classification — it selects across the WHOLE catalog under a cost ceiling so an
        // agent-ineligible-but-fast model (gpt-oss-120b @ Cerebras) can win, ranked by raw throughput.
        // 6th element = requiresAgentEligible (DECOUPLED from appliesAgentLoopClassification): MEMORY_MANAGEMENT keeps
        // the agentTier filter (stays in the CHEAP band) but does NOT require agentEligible — its op is structured-JSON
        // extract/reconcile, not the interactive tool-calling agent, so a strong cheap model held out of the live agent
        // (agentEligible=false, e.g. a reasoning model with non-uniform provider tool-calling) must still win it.
        [$tier, $interactive, $objective, $requiresToolCalling, $appliesAgentLoopClassification, $requiresAgentEligible] = match ($scope) {
            ModelScope::AGENTIC => [ModelTier::CHEAP, true, ModelObjective::CAPABILITY, true, true, true],
            ModelScope::COMPACTION => [ModelTier::CHEAP, false, ModelObjective::COST, true, true, true],
            // MEMORY_MANAGEMENT selects over the hand-vetted agent-CHEAP band (appliesAgentLoopClassification = true) by
            // CAPABILITY — the memory curator's extract (type/scope classification) and reconcile (ADD/UPDATE/INVALIDATE/
            // CONFLICT) decisions are quality-dominant, so it wants the SMARTEST cheap model, not the whole-catalog
            // throughput pick. This resolves to the same model as AGENTIC (Qwen3-235B — eval: better memory extract
            // type/scope classification than the throughput pick gpt-oss-120b, AND cheaper $0.09 vs $0.20/1M; the op is
            // async so raw throughput is irrelevant). Non-interactive + no tool-calling required (memory ops are plain
            // JSON-output language ops). It is therefore NO LONGER lock-stepped with FAST_CHEAP.
            ModelScope::MEMORY_MANAGEMENT => [ModelTier::CHEAP, false, ModelObjective::CAPABILITY, false, true, false],
            // FAST_CHEAP = the non-agentic, no-tool-calling SPEED pick over the WHOLE cheap catalog under the cost
            // ceiling, ranked by throughput (its best model, gpt-oss-120b @ Cerebras, sits outside the agent-CHEAP band).
            ModelScope::FAST_CHEAP => [ModelTier::CHEAP, true, ModelObjective::SPEED, false, false, false],
            // ORCHESTRATOR (an agent supervisor): async checkpoint calls — briefing in, JSON verdict out.
            // The scope is TIER-INDEPENDENT — its default is a PINNED model ({@see SCOPE_PINNED_DEFAULT_MODELS} = M3,
            // the eval-gate winner), so getModelForScope short-circuits before this tuple. This tuple is only the
            // FALLBACK when the pinned model is absent: a quality pick (CAPABILITY) with no tool calling and no
            // agent-loop filter (the supervisor never runs the interactive tool loop).
            ModelScope::ORCHESTRATOR => [ModelTier::STANDARD, false, ModelObjective::CAPABILITY, false, false, false],
            // SUMMARIZATION = a quality-leaning STANDARD-tier one-off (product/plan/flag descriptions, cached 24h).
            // A tier ABOVE the cost-dominant COMPACTION summarizer because it is low-volume and its output is a
            // customer-facing product description, so it selects the SMARTEST model in the curated STANDARD band
            // (appliesAgentLoopClassification = true keeps it in the tier band, no cost ceiling). Non-interactive, no
            // tool-calling, and NOT agent-eligible-gated (mirrors MEMORY_MANAGEMENT: a plain text/JSON language op).
            ModelScope::SUMMARIZATION => [ModelTier::STANDARD, false, ModelObjective::CAPABILITY, false, true, false],
            default => [ModelTier::CHEAP, false, ModelObjective::COST, true, true, true],
        };
        // The FAST_CHEAP cost ceiling defines "cheap" when agent-tier membership no longer does (blended $/1M tokens).
        $maxBlendedCostPer1MUsd = $appliesAgentLoopClassification ? null : self::FAST_CHEAP_MAX_BLENDED_COST_PER_1M_USD;
        return new ModelSelectionContext(
            new ModelManagerPolicy(
                preferredTier: $tier,
                maxBlendedCostPer1MUsd: $maxBlendedCostPer1MUsd,
                interactive: $interactive,
                requiresToolCalling: $requiresToolCalling,
                appliesAgentLoopClassification: $appliesAgentLoopClassification,
                requiresAgentEligible: $requiresAgentEligible,
            ),
            requiredInputTokens: $requiredInputTokens,
            scope: $scope,
            objective: $objective,
        );
    }

    /**
     * Select the model for the given context from the live catalog (by curated agentTier), cached by the context's
     * {@see ModelSelectionContext::uniqueKey()} — the scan + scoring run ONCE per distinct context per process.
     *
     * @throws BadRequestException when no model classified for the (effective) tier is eligible.
     */
    public function selectModel(ModelSelectionContext $context): ?AIModel
    {
        $cacheKey = $context->uniqueKey();
        $decision = self::$selectionCache[$cacheKey] ?? null;
        if ($decision === null) {
            $decision = $this->selectCandidate($context, $this->loadCandidates());
            self::$selectionCache[$cacheKey] = $decision;
        }
        $this->recordDecision($decision);

        // This service is a shared container instance: the flag holds for this one lookup only, so a later caller's
        // getAIModelByName() still returns null on an unknown name instead of throwing.
        $throwErrorsBeforeLookup = $this->throwErrors;
        $this->throwErrors = true;
        try {
            return $this->getAIModelByName($decision->modelName);
        } finally {
            $this->throwErrors = $throwErrorsBeforeLookup;
        }
    }

    /** Drop the per-process selection cache (e.g. eval/calibration runs that mutate the catalog between selections). */
    public static function clearSelectionCache(): void
    {
        self::$selectionCache = [];
    }

    /**
     * The pure selection over the CURATED per-model `agentTier` classification. Keeps the candidates tagged for the
     * effective tier that are eligible for this call (tool-calling-capable + cost ceiling + interactive speed +
     * context window), then ranks by price/performance/speed. `preferredVendor` prefers the best-by-objective eligible
     * of that vendor. Operates on candidate projections only (no catalog/DI) — the unit-test seam.
     *
     * @param ModelCandidate[] $candidates
     * @throws BadRequestException when no model classified for the tier is eligible (sanity guard).
     */
    public function selectCandidate(ModelSelectionContext $context, array $candidates): ModelSelectionDecision
    {
        $policy = $context->policy;
        $tier = $this->effectiveTier($policy->preferredTier, $context->minTier);

        $inTier = []; // tagged for this tier (pre-constraint) — for the decision counts
        $eligible = [];
        foreach ($candidates as $candidate) {
            // FAST_CHEAP (appliesAgentLoopClassification=false) selects across the WHOLE catalog under the cost
            // ceiling, so it skips BOTH the agentTier filter and the agentEligible gate below — that is what lets an
            // agent-ineligible-but-fast model (gpt-oss-120b) win. Every other scope stays inside its curated tier band.
            if ($policy->appliesAgentLoopClassification && $candidate->agentTier !== $tier) {
                continue; // not classified into this tier (the per-model models.php flag is the source of truth)
            }
            $inTier[] = $candidate;
            // Hard eligibility gates: overall agentic fitness (live-test disqualifications — fabricators /
            // non-chainers) is required for agent-classified scopes; native tool-calling only when the policy demands
            // it (agentic scopes). A NON-agentic scope (FAST_CHEAP rerank, compaction, memory) sets
            // requiresToolCalling=false, so a fast non-tool model stays eligible.
            if ($policy->requiresAgentEligible && !$candidate->agentEligible) {
                continue;
            }
            if ($policy->requiresToolCalling && !$candidate->supportsNativeToolCalling) {
                continue;
            }
            if ($policy->maxBlendedCostPer1MUsd !== null
                && $this->blendedCost($candidate) > $policy->maxBlendedCostPer1MUsd) {
                continue;
            }
            if ($policy->interactive && $context->minSpeedScore !== null
                && ($candidate->speedScore === null || $candidate->speedScore < $context->minSpeedScore)) {
                continue;
            }
            // Context window is a HARD constraint: usable input window below the required size (or unknown) → skip.
            if ($context->requiredInputTokens !== null) {
                $window = $candidate->effectiveInputWindow();
                if ($window === null || $window < $context->requiredInputTokens) {
                    continue;
                }
            }
            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            throw new BadRequestException(
                'AIModelsService: no model classified for tier "' . $tier . '" is eligible (agentTier + tool-calling'
                . ($policy->maxBlendedCostPer1MUsd !== null ? ' + cost ceiling' : '')
                . ($context->requiredInputTokens !== null ? ' + input window ≥ ' . $context->requiredInputTokens : '')
                . ')'
            );
        }

        // Rank the eligible survivors by PRICE/PERFORMANCE/SPEED: Pareto-prune over (capability↑, speed↑, cost↓) — drop
        // any model another beats on all three — then order the non-dominated set by the scope's objective. null → COST.
        $objective = $context->objective ?? ModelObjective::COST;
        $ranked = $this->optimize($this->paretoFront($eligible), $objective);

        $winner = $ranked[0];
        $vendorApplied = false;
        if ($policy->preferredVendor !== null) {
            foreach ($ranked as $candidate) { // objective-ordered → first vendor match is the best-by-objective of that vendor
                if ($candidate->vendor === $policy->preferredVendor) {
                    $winner = $candidate;
                    $vendorApplied = true;
                    break;
                }
            }
        }

        return new ModelSelectionDecision(
            modelName: $winner->name,
            effectiveTier: $tier,
            agenticScore: $winner->agenticScore,
            blendedCostPer1MUsd: $this->blendedCost($winner),
            vendor: $winner->vendor,
            vendorPreferenceApplied: $vendorApplied,
            candidateCount: count($eligible),
            inTierCount: count($inTier),
            objective: $objective,
            reason: 'tier=' . $tier . ' obj=' . $objective . ' paretoRanked(' . count($eligible) . '/' . count($inTier) . ' eligible)'
                . ($context->requiredInputTokens !== null ? ' ctx≥' . $context->requiredInputTokens : '')
                . ($policy->preferredVendor !== null
                    ? ' vendorPref=' . $policy->preferredVendor . ($vendorApplied ? '(applied)' : '(no-qualifier)')
                    : ''),
        );
    }

    /**
     * Every AIModel the agent may run on — language models that are agent-eligible, tool-calling capable, and rated —
     * as lightweight {@see AgentEligibleModel} descriptors (name + tier + scores + blended cost), best-first (highest
     * agentic score). NOT Pareto-pruned: the admin model picker shows the full agent-suitable set. Source of the admin
     * console's model dropdown.
     */
    public function getAgentEligibleModels(): AgentEligibleModels
    {
        $eligible = array_values(array_filter(
            $this->loadCandidates(),
            static fn (ModelCandidate $candidate): bool =>
                $candidate->agentEligible
                && $candidate->supportsNativeToolCalling
                && $candidate->agenticScore !== null
                // Untiered models are NOT curated into a selectable tier → not actually available to run on; exclude them.
                && $candidate->agentTier !== null
        ));
        usort(
            $eligible,
            static fn (ModelCandidate $a, ModelCandidate $b): int => ($b->agenticScore ?? 0) <=> ($a->agenticScore ?? 0)
        );

        $agentEligibleModels = new AgentEligibleModels();
        foreach ($eligible as $candidate) {
            $agentEligibleModel = new AgentEligibleModel();
            $agentEligibleModel->name = $candidate->name;
            $agentEligibleModel->vendor = $candidate->vendor;
            $agentEligibleModel->tier = $candidate->agentTier;
            $agentEligibleModel->agenticScore = $candidate->agenticScore;
            $agentEligibleModel->speedScore = $candidate->speedScore;
            $agentEligibleModel->tokensPerSecond = $candidate->tokensPerSecond;
            $agentEligibleModel->timeToFirstTokenMs = $candidate->timeToFirstTokenMs;
            $agentEligibleModel->blendedCostPer1MUsd = round($this->blendedCost($candidate), 2);
            // The context window + compaction threshold are DISPLAY/config facts, not selection axes — read them
            // straight from the AIModel (config-hydrated, cheap), NOT from the pure ModelCandidate projection.
            $aiModel = $this->getAIModelByName($candidate->name);
            $agentEligibleModel->contextWindowTokens = $aiModel?->contextWindowTokens ?? $candidate->maxInputTokens;
            $agentEligibleModel->effectiveContextTokens = $aiModel?->effectiveContextTokens;
            $agentEligibleModels->add($agentEligibleModel);
        }
        return $agentEligibleModels;
    }

    /**
     * The objective candidate set: language models that are rated, Pareto-non-dominated. Useful for the
     * fleet-mis-route guard and eval reports.
     *
     * @throws BadRequestException
     */
    public function getAgenticParetoFrontier(): AIModels
    {
        $rated = array_values(array_filter(
            $this->loadCandidates(),
            static fn (ModelCandidate $c): bool => $c->agenticScore !== null && $c->supportsNativeToolCalling && $c->agentEligible
        ));
        return $this->candidatesToAIModels($this->paretoFront($rated));
    }

    /**
     * Filtered + Pareto-pruned + objective-ordered candidate set (best-first) — the §2.1 pipeline minus the policy
     * wrapper. Used by the calibration harness and the fleet guard.
     *
     * @throws BadRequestException
     */
    public function selectAgenticModels(int $minScore, ?float $maxCostPer1M, ?int $minSpeedScore, string $objective): AIModels
    {
        $qualifying = [];
        foreach ($this->loadCandidates() as $candidate) {
            if (!$candidate->supportsNativeToolCalling || !$candidate->agentEligible) {
                continue;
            }
            if ($candidate->agenticScore === null || $candidate->agenticScore < $minScore) {
                continue;
            }
            if ($maxCostPer1M !== null && $this->blendedCost($candidate) > $maxCostPer1M) {
                continue;
            }
            if ($minSpeedScore !== null && ($candidate->speedScore === null || $candidate->speedScore < $minSpeedScore)) {
                continue;
            }
            $qualifying[] = $candidate;
        }
        $ordered = $this->optimize($this->paretoFront($qualifying), $objective);
        return $this->candidatesToAIModels($ordered);
    }

    public function getLastDecision(): ?ModelSelectionDecision
    {
        return $this->lastDecision;
    }

    /**
     * Stronger of the policy tier and an optional requested floor (by {@see ModelTier} rank).
     *
     * @throws BadRequestException on an unknown tier.
     */
    protected function effectiveTier(string $policyTier, ?string $minTier): string
    {
        if ($minTier === null || !ModelTier::isValid($minTier)) {
            return $policyTier;
        }
        return ModelTier::rank($minTier) > ModelTier::rank($policyTier) ? $minTier : $policyTier;
    }

    protected function blendedCost(ModelCandidate $candidate): float
    {
        return $candidate->blendedCostPer1MUsd(self::BLENDED_INPUT_WEIGHT, self::BLENDED_OUTPUT_WEIGHT);
    }

    /**
     * Pareto frontier over (agenticScore ↑, speedScore ↑, blendedCost ↓). A candidate is dropped if another is at
     * least as good on all three axes and strictly better on at least one. Null speed treated as worst (0).
     *
     * @param ModelCandidate[] $candidates
     * @return ModelCandidate[]
     */
    protected function paretoFront(array $candidates): array
    {
        $front = [];
        foreach ($candidates as $a) {
            $dominated = false;
            foreach ($candidates as $b) {
                if ($a === $b) {
                    continue;
                }
                if ($this->dominates($b, $a)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $front[] = $a;
            }
        }
        return $front;
    }

    /**
     * True if $a dominates $b: ≥ on capability and speed, ≤ on blended cost, and strictly better on ≥ 1 axis.
     */
    protected function dominates(ModelCandidate $a, ModelCandidate $b): bool
    {
        $aScore = $a->agenticScore ?? 0;
        $bScore = $b->agenticScore ?? 0;
        $aSpeed = $a->speedScore ?? 0;
        $bSpeed = $b->speedScore ?? 0;
        $aCost = $this->blendedCost($a);
        $bCost = $this->blendedCost($b);

        $atLeastAsGood = $aScore >= $bScore && $aSpeed >= $bSpeed && $aCost <= $bCost;
        if (!$atLeastAsGood) {
            return false;
        }
        return $aScore > $bScore || $aSpeed > $bSpeed || $aCost < $bCost;
    }

    /**
     * Order candidates best-first by the objective, with deterministic tie-breaks (always ending on name asc).
     *
     * @param ModelCandidate[] $candidates
     * @return ModelCandidate[]
     */
    protected function optimize(array $candidates, string $objective): array
    {
        $ordered = $candidates;
        usort($ordered, function (ModelCandidate $a, ModelCandidate $b) use ($objective): int {
            $aScore = $a->agenticScore ?? 0;
            $bScore = $b->agenticScore ?? 0;
            $aCost = $this->blendedCost($a);
            $bCost = $this->blendedCost($b);
            $aVal = $a->valuePerDollar(self::BLENDED_INPUT_WEIGHT, self::BLENDED_OUTPUT_WEIGHT) ?? -INF;
            $bVal = $b->valuePerDollar(self::BLENDED_INPUT_WEIGHT, self::BLENDED_OUTPUT_WEIGHT) ?? -INF;
            $aSpeed = $a->speedScore ?? 0;
            $bSpeed = $b->speedScore ?? 0;
            // SPEED ranks by RAW tokens/sec, not the 0–100 speedScore: the score caps at 100, so gpt-oss-120b
            // @Cerebras (≈526 tok/s) and a 300 tok/s model would tie at 100 and lose the real ordering. Raw tps
            // keeps the routed top-provider distinction the FAST_CHEAP scope depends on.
            $aTps = $a->tokensPerSecond ?? 0.0;
            $bTps = $b->tokensPerSecond ?? 0.0;

            $cmp = match ($objective) {
                ModelObjective::CAPABILITY => ($bScore <=> $aScore) ?: ($aCost <=> $bCost) ?: ($bSpeed <=> $aSpeed),
                ModelObjective::VALUE => ($bVal <=> $aVal) ?: ($aCost <=> $bCost) ?: ($bScore <=> $aScore),
                ModelObjective::SPEED => ($bTps <=> $aTps) ?: ($aCost <=> $bCost) ?: ($bScore <=> $aScore),
                default => ($aCost <=> $bCost) ?: ($bVal <=> $aVal) ?: ($bScore <=> $aScore), // COST
            };
            return $cmp ?: strcmp($a->name, $b->name);
        });
        return $ordered;
    }

    /**
     * Load the language-model catalog as candidate projections.
     *
     * @return ModelCandidate[]
     * @throws BadRequestException
     */
    protected function loadCandidates(): array
    {
        $candidates = [];
        foreach ($this->getAIModels()->getElements() as $model) {
            if ($model->type !== AIModel::TYPE_LANGUAGE) {
                continue;
            }
            $candidates[] = $this->candidateFromModel($model);
        }
        return $candidates;
    }

    protected function candidateFromModel(AIModel $model): ModelCandidate
    {
        $inputPer1M = 0.0;
        $outputPer1M = 0.0;
        $settings = $model->settings ?? null;
        if ($settings instanceof AILanguageModelSetting) {
            // Stored costs are per-1K USD; ×1000 = per-1M.
            $inputPer1M = ($settings->costsPer1000KInputTokens->amount ?? 0.0) * 1000.0;
            $outputPer1M = ($settings->costsPer1000KOutputTokens->amount ?? 0.0) * 1000.0;
        }

        $maxInputTokens = null;
        $practicalInputTokens = null;
        if ($settings instanceof AILanguageModelSetting) {
            $maxInputTokens = $settings->maxInputTokens;
            $practicalInputTokens = $settings->maxPracticallyUsableInputTokens;
        }

        return new ModelCandidate(
            name: $model->name,
            vendor: $model->vendor ?? null,
            agenticScore: $model->getAgenticIntelligenceScore(),
            speedScore: $model->getSpeedScore(),
            tokensPerSecond: $model->getTokensPerSecond(),
            inputCostPer1MUsd: $inputPer1M,
            outputCostPer1MUsd: $outputPer1M,
            maxInputTokens: $maxInputTokens,
            maxPracticallyUsableInputTokens: $practicalInputTokens,
            supportsNativeToolCalling: $model->supportsNativeToolCalling,
            agentTier: $model->agentTier,
            agentEligible: $model->agentEligible,
            timeToFirstTokenMs: $model->speed?->getTimeToFirstTokenMs(),
        );
    }

    /**
     * Build an AIModels set from candidates (preserving order) by resolving each by name.
     *
     * @param ModelCandidate[] $candidates
     */
    protected function candidatesToAIModels(array $candidates): AIModels
    {
        $setClass = DDDService::instance()->getContainerServiceClassNameForClass(AIModels::class);
        /** @var AIModels $set */
        $set = new $setClass();
        // shared container instance: the flag holds for these lookups only (see selectModel())
        $throwErrorsBeforeLookups = $this->throwErrors;
        $this->throwErrors = true;
        try {
            foreach ($candidates as $candidate) {
                $model = $this->getAIModelByName($candidate->name);
                if ($model !== null) {
                    $set->add($model);
                }
            }
        } finally {
            $this->throwErrors = $throwErrorsBeforeLookups;
        }
        return $set;
    }

    /**
     * Best-effort audit: keep the last decision and log it if a PSR logger is reachable.
     */
    protected function recordDecision(ModelSelectionDecision $decision): void
    {
        $this->lastDecision = $decision;
        try {
            $container = DDDBundle::getContainer();
            foreach ([LoggerInterface::class, 'logger'] as $serviceId) {
                if ($container->has($serviceId)) {
                    $logger = $container->get($serviceId);
                    if ($logger instanceof LoggerInterface) {
                        $logger->info('AIModelsService selection', $decision->toLogContext());
                        break;
                    }
                }
            }
        } catch (Throwable) {
            // logging is best-effort — never let it break selection.
        }
    }

    /**
     * The default compaction threshold (`effectiveContextTokens`) for a model whose catalog entry does not set one
     * explicitly — derived from its context-window class. The reliable-reasoning length is an ABSOLUTE token count, not
     * a fraction of the window (accuracy degrades with raw token count — RULER / Chroma Context-Rot / Gemini MRCR).
     * The per-class values: 128K→64k, 200K→50k, 256–272K→64k, 400K→96k,
     * 1M→128k; small windows fall back to ~60% of the window. Always capped at 80% of the window (the context-rot wall).
     */
    protected static function defaultEffectiveContextTokensForWindow(?int $window): ?int
    {
        if ($window === null || $window <= 0) {
            return null;
        }
        $threshold = match (true) {
            $window >= 1_000_000 => 128_000,
            $window >= 400_000 => 96_000,
            $window >= 250_000 => 64_000,
            $window >= 190_000 => 50_000,
            $window >= 120_000 => 64_000,
            default => (int)max(8_000, $window * 0.6),
        };
        return (int)min($threshold, (int)($window * 0.8));
    }
}
