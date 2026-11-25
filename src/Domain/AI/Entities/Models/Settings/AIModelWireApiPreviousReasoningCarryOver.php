<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The carry-over of a model's own earlier reasoning on ONE wire API (catalog key
 * `agenticUseCase.previousReasoningByWireApi.<wire api>`): for a value measured on that wire only. On a call over this
 * wire it replaces the model-wide `agenticUseCase.previousReasoning` as a whole (no field-wise merge);
 * {@see \DDD\Domain\AI\Entities\Models\AIModel::getAgenticPreviousReasoningCarryOver()}.
 *
 * Used inside {@see AIModelWireApiPreviousReasoningCarryOvers} on {@see AIModelAgenticUseCaseConfig}.
 */
class AIModelWireApiPreviousReasoningCarryOver extends ValueObject
{
    /**
     * @var string The wire API, one of {@see \DDD\Domain\AI\Entities\Models\AIModel::CATALOG_AGENT_WIRE_APIS}, or a
     *      wire API this package does not know, as written (trimmed)
     */
    public string $agentWireApi = '';

    /** @var AIModelPreviousReasoningCarryOver The carry-over on this wire API */
    public AIModelPreviousReasoningCarryOver $previousReasoning;

    public function __construct(string $agentWireApi = '', ?AIModelPreviousReasoningCarryOver $previousReasoning = null)
    {
        $this->agentWireApi = $agentWireApi;
        $this->previousReasoning = $previousReasoning ?? new AIModelPreviousReasoningCarryOver();
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForAgentWireApi($this->agentWireApi);
    }

    /** The unique key of the element for `$agentWireApi`, built from the declaring class (also for a subclass) */
    public static function uniqueKeyForAgentWireApi(string $agentWireApi): string
    {
        return self::class . '_' . $agentWireApi;
    }
}
