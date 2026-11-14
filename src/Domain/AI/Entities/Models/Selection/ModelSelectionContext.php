<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The input to {@see \DDD\Domain\AI\Services\AIModelsService::selectModel()} / {@see \DDD\Domain\AI\Services\AIModelsService::getModelForScope()}:
 * the policy + optional per-call overrides + the {@see ModelScope} the selection serves. A DDD ValueObject so its
 * {@see self::uniqueKey()} can key the AIModelsService's per-process selection cache — the same context (scope + policy +
 * constraints) always resolves to the same model within a run, so the catalog scan + scoring runs ONCE per distinct
 * context (owner directive). Still freely constructable (incl. unit tests); the custom constructor keeps every
 * existing `new ModelSelectionContext($policy, …)` call-site working.
 */
class ModelSelectionContext extends ValueObject
{
    /** @var ModelManagerPolicy The declarative selection policy (preferred tier, vendor, cost ceiling, interactive). */
    public ModelManagerPolicy $policy;

    /**
     * @var string|null A {@see ModelTier}::* floor a feature can request for this call (e.g. a write task asking for
     *      ≥ STANDARD). The effective tier is the stronger of policy->preferredTier and this floor.
     */
    public ?string $minTier;

    /** @var int|null Optional minimum speed score, applied only when {@see ModelManagerPolicy::$interactive}. */
    public ?int $minSpeedScore;

    /**
     * @var int|null Required input-token capacity for this call (system prompt + tool defs + accumulating tool results
     *      + history). A HARD constraint: models whose usable window is below this are excluded. Null = no requirement.
     */
    public ?int $requiredInputTokens;

    /**
     * @var string|null The {@see ModelScope}::* this selection serves (AGENTIC / COMPACTION / MEMORY_MANAGEMENT). Null
     *      for a raw policy-only selection. Part of the cache key — different scopes resolve independently.
     */
    public ?string $scope;

    /**
     * @var string|null The {@see ModelObjective}::* the survivor set is ranked by AFTER the hard filters + Pareto prune
     *      (CAPABILITY = the live agent: best agentic reasoning; COST = the cheap background tasks: cheapest of the
     *      non-dominated; VALUE = best score-per-dollar). Null = COST (the safe default for a raw policy-only call).
     */
    public ?string $objective;

    public function __construct(
        ModelManagerPolicy $policy,
        ?string $minTier = null,
        ?int $minSpeedScore = null,
        ?int $requiredInputTokens = null,
        ?string $scope = null,
        ?string $objective = null,
    ) {
        parent::__construct();
        $this->policy = $policy;
        $this->minTier = $minTier;
        $this->minSpeedScore = $minSpeedScore;
        $this->requiredInputTokens = $requiredInputTokens;
        $this->scope = $scope;
        $this->objective = $objective;
    }

    /**
     * Content key over every field that affects the selection outcome (scope + the full policy + the per-call
     * constraints). Two contexts with the same key MUST resolve to the same model — that is what makes the
     * AIModelsService's static selection cache correct.
     */
    public function uniqueKey(): string
    {
        $parts = [
            'scope=' . ($this->scope ?? '-'),
            'tier=' . $this->policy->preferredTier,
            'vendor=' . ($this->policy->preferredVendor ?? '-'),
            'maxCost=' . ($this->policy->maxBlendedCostPer1MUsd !== null ? (string)$this->policy->maxBlendedCostPer1MUsd : '-'),
            'interactive=' . ($this->policy->interactive ? '1' : '0'),
            'toolCalling=' . ($this->policy->requiresToolCalling ? '1' : '0'),
            'agentLoopClassification=' . ($this->policy->appliesAgentLoopClassification ? '1' : '0'),
            'agentEligible=' . ($this->policy->requiresAgentEligible ? '1' : '0'),
            'minTier=' . ($this->minTier ?? '-'),
            'minSpeed=' . ($this->minSpeedScore !== null ? (string)$this->minSpeedScore : '-'),
            'reqInputTokens=' . ($this->requiredInputTokens !== null ? (string)$this->requiredInputTokens : '-'),
            'objective=' . ($this->objective ?? '-'),
        ];
        return self::class . ':' . implode('|', $parts);
    }
}
