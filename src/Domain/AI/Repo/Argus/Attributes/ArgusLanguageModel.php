<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * Declares how an Argus repo using {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait} calls its model:
 * the model (fixed, by catalog tier, or by scope), the prompts, the response format, the transport mode and the
 * OpenRouter provider routing. Read once per instance by the trait.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ArgusLanguageModel
{
    use SerializerTrait, BaseAttributeTrait;

    /** @var string Response format JSON Object */
    public const string RESPONSE_FORMAT_JSON_OBJECT = 'JSON_OBJECT';

    /** @var string Response format Default */
    public const string RESPONSE_FORMAT_DEFAULT = 'DEFAULT';

    /**
     * Provider-prioritization strategy — HOW OpenRouter picks the provider serving the selected model (the
     * `provider.sort` body param). The model SELECTION (which model) is orthogonal; this is which PROVIDER of that model
     * wins. Mapped by {@see mapProviderPrioritizationStrategyToSort()}.
     */
    /** @var string OpenRouter's own default provider routing (no `sort` sent). */
    public const string PROVIDER_PRIORITIZATION_STRATEGY_DEFAULT = 'DEFAULT';
    /** @var string Fastest provider by tokens/sec → `sort: throughput`. */
    public const string PROVIDER_PRIORITIZATION_STRATEGY_SPEED = 'SPEED';
    /** @var string Lowest time-to-first-token provider → `sort: latency`. */
    public const string PROVIDER_PRIORITIZATION_STRATEGY_LATENCY = 'LATENCY';
    /** @var string Cheapest provider → `sort: price`. */
    public const string PROVIDER_PRIORITIZATION_STRATEGY_PRICE = 'PRICE';

    /** @var string|null The default AIModel name to use */
    public ?string $defaultAIModelName = '';

    /** @var string|null The default AIPrompt name to use */
    public ?string $defaultAIPromptName = '';

    /** @var int|null The default AIPrompt version to use (kept for compatibility; prompts are resolved by name) */
    public ?int $defaultAIPromptVersion = null;

    /** @var float|null The temperature value to be applied */
    public ?float $temperature = null;

    /** @var string|null The desired response Format */
    #[Choice(choices: [
            self::RESPONSE_FORMAT_JSON_OBJECT,
            self::RESPONSE_FORMAT_DEFAULT,
        ]
    )]
    public ?string $responseFormat = null;

    /**
     * @var string|null The system prompt name. When set, the Chat Completions payload puts the system prompt in the
     *      `system` role and leads the `user` content with the task prompt (string user content wrapped in
     *      `<user-data>…</user-data>`); when null, the task prompt takes the `system` role.
     */
    public ?string $systemPromptName = null;

    /** @var int|null The system prompt version to use (kept for compatibility; prompts are resolved by name) */
    public ?int $systemPromptVersion = null;

    /**
     * @var string[]|null Ordered list of OpenRouter provider slugs to prefer (e.g. ['groq', 'cerebras']). Only applied
     *      on an OpenRouter-bound egress, and only when neither a runtime provider pin nor the model's catalog
     *      `providerFilters` decide the order. Translated into `provider.order` with `allow_fallbacks: true`.
     */
    public ?array $preferredProviders = null;

    /**
     * @var string|null The usage scope of this call — an open string the application defines (e.g. a dotted
     *      `DOMAIN.FEATURE.OPERATION` name). Handed to the application's model-call gate and usage recorder and used as
     *      the call kind in Argus transport recordings.
     */
    public ?string $aiUsageScope = null;

    /** @var string|null The vendor API transport mode to use */
    #[Choice(choices: [
            ArgusOpenAIApiMode::CHAT_COMPLETIONS,
            ArgusOpenAIApiMode::RESPONSES,
        ]
    )]
    public ?string $apiMode = null;

    /**
     * Reasoning effort for reasoning models. Responses API: `reasoning.effort`; Chat Completions on an OpenRouter-bound
     * egress: `reasoning: {effort}`; direct OpenAI Chat Completions: `reasoning_effort`. Null = model default.
     */
    #[Choice(choices: [
            ArgusOpenAIReasoningEffort::NONE,
            ArgusOpenAIReasoningEffort::MINIMAL,
            ArgusOpenAIReasoningEffort::LOW,
            ArgusOpenAIReasoningEffort::MEDIUM,
            ArgusOpenAIReasoningEffort::HIGH,
            ArgusOpenAIReasoningEffort::XHIGH,
        ]
    )]
    public ?string $reasoningEffort = null;

    /**
     * @var string|null Fallback AIModel name. A response body carrying `status_code` 503 — and only 503 — triggers ONE
     *      retry of the whole load with this model, both caches bypassed. Must share the vendor of the first model; on a
     *      vendor mismatch the retry is skipped with a warning.
     */
    public ?string $fallbackAIModelName = null;

    /**
     * @var string|null When set (a {@see \DDD\Domain\AI\Entities\Models\Selection\ModelTier} constant), the model is
     *      selected from the scored catalog by {@see \DDD\Domain\AI\Services\AIModelsService::selectModel()} instead of
     *      {@see $defaultAIModelName}.
     */
    public ?string $preferredModelTier = null;

    /** @var string|null Soft vendor preference for the tier selection (an AIModel::VENDOR_* value). */
    public ?string $preferredModelVendor = null;

    /** @var float|null Optional hard ceiling on blended $/1M tokens for the tier selection. */
    public ?float $maxModelBlendedCostPer1MUsd = null;

    /**
     * @var string|null When set (a {@see \DDD\Domain\AI\Entities\Models\Selection\ModelScope} constant), the model is
     *      resolved by {@see \DDD\Domain\AI\Services\AIModelsService::getModelForScope()}; takes precedence over
     *      {@see $preferredModelTier} and {@see $defaultAIModelName} (the latter stays the fallback).
     */
    public ?string $modelScope = null;

    /**
     * @var bool Whether the application's budget check runs for this call. False for infrastructure calls that must not
     *      be capped by a customer budget; the usage is still recorded.
     */
    public bool $enforceBudget = true;

    /** @var bool Whether every call of this class asks for the vendor's priority service tier (fast mode). */
    public bool $fastMode = false;

    /**
     * @var string|null The provider-prioritization strategy for OpenRouter routing (a PROVIDER_PRIORITIZATION_STRATEGY_*
     *      constant) — the per-class default, overridable at runtime via
     *      {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait::setProviderPrioritizationStrategy()}.
     */
    #[Choice(choices: [
            self::PROVIDER_PRIORITIZATION_STRATEGY_DEFAULT,
            self::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
            self::PROVIDER_PRIORITIZATION_STRATEGY_LATENCY,
            self::PROVIDER_PRIORITIZATION_STRATEGY_PRICE,
        ]
    )]
    public ?string $providerPrioritizationStrategy = null;

    /**
     * Map THIS attribute's {@see $providerPrioritizationStrategy} to the OpenRouter `provider.sort` value, or null.
     */
    public function getOpenRouterProviderSort(): ?string
    {
        return self::mapProviderPrioritizationStrategyToSort($this->providerPrioritizationStrategy);
    }

    /**
     * A PROVIDER_PRIORITIZATION_STRATEGY_* constant → the OpenRouter `provider.sort` value, or null when no sort is sent
     * (DEFAULT / unset → OpenRouter's own routing). Static so a runtime override can be mapped without mutating the
     * shared attribute instance.
     */
    public static function mapProviderPrioritizationStrategyToSort(?string $providerPrioritizationStrategy): ?string
    {
        return match ($providerPrioritizationStrategy) {
            self::PROVIDER_PRIORITIZATION_STRATEGY_SPEED => 'throughput',
            self::PROVIDER_PRIORITIZATION_STRATEGY_LATENCY => 'latency',
            self::PROVIDER_PRIORITIZATION_STRATEGY_PRICE => 'price',
            default => null,
        };
    }

    /**
     * The first eight parameters keep the order of the 2.x attribute; everything after them is new in 3.0 and is meant
     * to be passed by name.
     *
     * @param string[]|null $preferredProviders
     */
    public function __construct(
        ?string $defaultAIModelName = null,
        ?string $defaultAIPromptName = null,
        ?int $defaultAIPromptVersion = null,
        ?float $temperature = null,
        ?string $responseFormat = self::RESPONSE_FORMAT_DEFAULT,
        ?string $systemPromptName = null,
        ?int $systemPromptVersion = null,
        ?array $preferredProviders = null,
        ?string $aiUsageScope = null,
        ?string $fallbackAIModelName = null,
        ?string $apiMode = null,
        ?string $reasoningEffort = null,
        ?string $preferredModelTier = null,
        ?string $preferredModelVendor = null,
        ?float $maxModelBlendedCostPer1MUsd = null,
        ?string $modelScope = null,
        bool $enforceBudget = true,
        ?string $providerPrioritizationStrategy = null,
        bool $fastMode = false,
    ) {
        $this->defaultAIModelName = $defaultAIModelName;
        $this->defaultAIPromptName = $defaultAIPromptName;
        $this->defaultAIPromptVersion = $defaultAIPromptVersion;
        $this->temperature = $temperature;
        $this->responseFormat = $responseFormat;
        $this->systemPromptName = $systemPromptName;
        $this->systemPromptVersion = $systemPromptVersion;
        $this->preferredProviders = $preferredProviders;
        $this->aiUsageScope = $aiUsageScope;
        $this->fallbackAIModelName = $fallbackAIModelName;
        $this->apiMode = $apiMode;
        $this->reasoningEffort = $reasoningEffort;
        $this->preferredModelTier = $preferredModelTier;
        $this->preferredModelVendor = $preferredModelVendor;
        $this->maxModelBlendedCostPer1MUsd = $maxModelBlendedCostPer1MUsd;
        $this->modelScope = $modelScope;
        $this->enforceBudget = $enforceBudget;
        $this->providerPrioritizationStrategy = $providerPrioritizationStrategy;
        $this->fastMode = $fastMode;
    }
}
