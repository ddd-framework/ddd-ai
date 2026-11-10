<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The booked AI costs per usage scope, one element per scope.
 *
 * @method AIUsageScopeBookedCost first()
 * @method AIUsageScopeBookedCost getByUniqueKey(string $uniqueKey)
 * @method AIUsageScopeBookedCost[] getElements()
 * @property AIUsageScopeBookedCost[] $elements
 */
class AIUsageScopeBookedCosts extends ObjectSet
{
    /**
     * @param string $usageScope
     * @return float The USD booked under the scope, 0.0 when nothing was booked under it
     */
    public function getCostInUSDForUsageScope(string $usageScope): float
    {
        return $this->getByUniqueKey(AIUsageScopeBookedCost::uniqueKeyStatic($usageScope))?->costInUSD ?? 0.0;
    }

    /**
     * Adds an amount to what is booked under the scope.
     *
     * @param string $usageScope
     * @param float $costInUSD
     * @return void
     */
    public function addCostInUSDForUsageScope(string $usageScope, float $costInUSD): void
    {
        $aIUsageScopeBookedCost = $this->getByUniqueKey(AIUsageScopeBookedCost::uniqueKeyStatic($usageScope));
        if ($aIUsageScopeBookedCost === null) {
            $aIUsageScopeBookedCost = new AIUsageScopeBookedCost($usageScope);
            $this->add($aIUsageScopeBookedCost);
        }
        $aIUsageScopeBookedCost->costInUSD = round($aIUsageScopeBookedCost->costInUSD + $costInUSD, 8);
    }
}
