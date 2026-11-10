<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The AI costs booked during one piece of work: every amount a usage log wrote while the work ran — a web search
 * fee, a model reading a page, a decision call inside a tool. The amounts themselves are booked where the
 * application books its AI costs; this object only says which work caused them.
 *
 * While the work runs it is an open recording of
 * {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::startRecordingBookedCosts()}; afterwards the caller stores
 * it with the work it belongs to (e.g. on a tool call), where every reader of a total cost adds it once.
 */
class AIUsageBookedCosts extends ValueObject
{
    /** @var float The sum of every booked amount in USD. */
    public float $totalCostInUSD = 0.0;

    /** @var int How many amounts were booked. */
    public int $bookingCount = 0;

    /** @var AIUsageScopeBookedCosts The USD booked per usage scope */
    public AIUsageScopeBookedCosts $scopeBookedCosts;

    public function __construct()
    {
        $this->scopeBookedCosts = new AIUsageScopeBookedCosts();
        parent::__construct();
    }

    /**
     * Adds one booked amount under its usage scope; an amount of zero or less is no booking.
     *
     * @param string $usageScope
     * @param float $costInUSD
     * @return void
     */
    public function addBookedCost(string $usageScope, float $costInUSD): void
    {
        if ($costInUSD <= 0.0) {
            return;
        }
        $this->totalCostInUSD = round($this->totalCostInUSD + $costInUSD, 8);
        $this->bookingCount++;
        $this->scopeBookedCosts->addCostInUSDForUsageScope($usageScope, $costInUSD);
    }

    /**
     * Adds every booking of `$otherBookedCosts` (its total, its count, its amounts per scope) — the costs of a piece
     * of work that ran inside this one under its own recording, or costs stored earlier and played back.
     *
     * @param AIUsageBookedCosts $otherBookedCosts
     * @return void
     */
    public function addBookedCostsOf(AIUsageBookedCosts $otherBookedCosts): void
    {
        if (!$otherBookedCosts->hasBookedCosts()) {
            return;
        }
        $this->totalCostInUSD = round($this->totalCostInUSD + $otherBookedCosts->totalCostInUSD, 8);
        $this->bookingCount += $otherBookedCosts->bookingCount;
        foreach ($otherBookedCosts->scopeBookedCosts->getElements() as $aIUsageScopeBookedCost) {
            $this->scopeBookedCosts->addCostInUSDForUsageScope(
                $aIUsageScopeBookedCost->usageScope,
                $aIUsageScopeBookedCost->costInUSD
            );
        }
    }

    public function hasBookedCosts(): bool
    {
        return $this->bookingCount > 0;
    }
}
