<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Per-model configuration for the AGENTIC use case (tool-calling agent loop: reasoning effort, temperature, the
 * carry-over of the model's own earlier reasoning), scoped to that use case only — NOT
 * intrinsic model facts (those stay on {@see AILanguageModelSetting}) and NOT a static call-site default. It rides
 * along with whatever model the AIModelsService selects, and is applied to the request ONLY when the egress conversation
 * opts in (the agentic egress), via {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait}. A non-agentic
 * use of the same model keeps the provider defaults.
 *
 * Why a use-case block and not flat settings / a `#[ArgusLanguageModel]` attribute: the same model wants different
 * settings per use case (agentic → low reasoning for fast, zielgerichtete tool-calls; analysis → high), and the model
 * is chosen dynamically, so the config must travel with the selected model, not with one call-site class.
 *
 * Vendor mapping (done in the trait, proxy-normalised): `reasoningEffort` → OpenAI `reasoning_effort` /
 * OpenRouter `reasoning: {effort}` (which the proxy maps to Anthropic thinking-budget / Gemini thinking-level for
 * those vendors). `temperature` is sent only when set (null = provider default — correct for Gemini 3.x which
 * mandates 1.0 and for Claude where thinking ignores temperature).
 */
class AIModelAgenticUseCaseConfig extends ValueObject
{
    /**
     * @var string|null Normalised reasoning intensity for the agent loop ('minimal' | 'low' | 'medium' | 'high' |
     *      'xhigh'), or null = provider default. Lower = faster, more zielgerichtete tool-calling and fewer
     *      reasoning-only empty turns; higher = deeper multi-step reasoning.
     */
    public ?string $reasoningEffort = null;

    /**
     * @var float|null Sampling temperature for the agent loop, or null = provider default. Leave null for Gemini 3.x
     *      (mandates 1.0) and Claude-with-thinking (ignored); a value is honoured for OpenAI.
     */
    public ?float $temperature = null;

    /**
     * @var AIModelPreviousReasoningCarryOver|null Whether and how far the agent sends the model's own reasoning of
     *      earlier calls back (catalog key `agenticUseCase.previousReasoning`); null = the agent's defaults.
     */
    public ?AIModelPreviousReasoningCarryOver $previousReasoning = null;

    public function __construct(?string $reasoningEffort = null, ?float $temperature = null, ?AIModelPreviousReasoningCarryOver $previousReasoning = null)
    {
        $this->reasoningEffort = $reasoningEffort;
        $this->temperature = $temperature;
        $this->previousReasoning = $previousReasoning;
        parent::__construct();
    }
}
