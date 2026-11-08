<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Result of the calibration run: per-model agentic-score vs eval pass-rate, plus the overall
 * rank-correlation that tells us whether the agentic score actually predicts our task success — i.e. whether the
 * tier bands rest on something real. A DDD ValueObject.
 */
class ModelCorrelationReport extends ValueObject
{
    /** @var array<int, array{modelName: string, agenticScore: int|null, passRate: float, caseCount: int}> */
    public array $rows;

    /**
     * @var float|null Spearman correlation of (agenticScore, passRate) over rated models; null when < 2 rated
     *      models or zero variance.
     */
    public ?float $rankCorrelation;

    /**
     * @param array<int, array{modelName: string, agenticScore: int|null, passRate: float, caseCount: int}> $rows
     */
    public function __construct(
        array $rows,
        ?float $rankCorrelation,
    ) {
        parent::__construct();
        $this->rows = $rows;
        $this->rankCorrelation = $rankCorrelation;
    }
}
