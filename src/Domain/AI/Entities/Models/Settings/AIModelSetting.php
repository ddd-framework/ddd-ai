<?php

declare (strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Common\Entities\Settings\Setting;

class AIModelSetting extends Setting
{
    /**
     * @var MoneyAmount|null The price of one second of a session with the model (a realtime voice session), catalog key
     * `costsPerSessionSecondInUSD`; null = the model is not priced by session time
     */
    public ?MoneyAmount $costsPerSessionSecond = null;

    /**
     * The cost of `$sessionSeconds` seconds of a session; null when the model carries no price per second.
     *
     * @param float $sessionSeconds The seconds of the session (negative counts as 0)
     * @return MoneyAmount|null
     */
    public function getEstimatedCostsForSessionSeconds(float $sessionSeconds): ?MoneyAmount
    {
        if ($this->costsPerSessionSecond === null) {
            return null;
        }
        return new MoneyAmount(max(0.0, $sessionSeconds) * (float)($this->costsPerSessionSecond->amount ?? 0.0), $this->costsPerSessionSecond->currency ?? 'USD');
    }
}
