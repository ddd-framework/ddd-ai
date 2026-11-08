<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The audit record of one selection. Returned by the pure pipeline and logged; carries the
 * full reason so a pick is traceable (and so the fleet-mis-route guard can diff picks before/after a catalog change).
 * A DDD ValueObject.
 */
class ModelSelectionDecision extends ValueObject
{
    /** @var string The chosen AIModel name. */
    public string $modelName;

    /** @var string The tier actually used (stronger of policy tier and any requested floor). */
    public string $effectiveTier;

    /** @var int|null Chosen model's agentic score. */
    public ?int $agenticScore;

    /** @var float Chosen model's blended cost. */
    public float $blendedCostPer1MUsd;

    /** @var string|null Chosen model's vendor. */
    public ?string $vendor;

    /** @var bool True if the soft vendor preference narrowed the choice. */
    public bool $vendorPreferenceApplied;

    /** @var int Number of candidates that survived the tier floor + ceilings. */
    public int $candidateCount;

    /** @var int Number of candidates classified into the effective tier (pre-constraint headcount). */
    public int $inTierCount;

    /** @var string The {@see ModelObjective}::* the selection optimised for. */
    public string $objective;

    /** @var string Human-readable summary for logs. */
    public string $reason;

    public function __construct(
        string $modelName,
        string $effectiveTier,
        ?int $agenticScore,
        float $blendedCostPer1MUsd,
        ?string $vendor,
        bool $vendorPreferenceApplied,
        int $candidateCount,
        int $inTierCount,
        string $objective,
        string $reason,
    ) {
        parent::__construct();
        $this->modelName = $modelName;
        $this->effectiveTier = $effectiveTier;
        $this->agenticScore = $agenticScore;
        $this->blendedCostPer1MUsd = $blendedCostPer1MUsd;
        $this->vendor = $vendor;
        $this->vendorPreferenceApplied = $vendorPreferenceApplied;
        $this->candidateCount = $candidateCount;
        $this->inTierCount = $inTierCount;
        $this->objective = $objective;
        $this->reason = $reason;
    }

    /** @return array<string, mixed> Structured form for logging / CLI output. */
    public function toLogContext(): array
    {
        return [
            'modelName' => $this->modelName,
            'effectiveTier' => $this->effectiveTier,
            'agenticScore' => $this->agenticScore,
            'blendedCostPer1MUsd' => $this->blendedCostPer1MUsd,
            'vendor' => $this->vendor,
            'vendorPreferenceApplied' => $this->vendorPreferenceApplied,
            'candidateCount' => $this->candidateCount,
            'inTierCount' => $this->inTierCount,
            'objective' => $this->objective,
            'reason' => $this->reason,
        ];
    }
}
