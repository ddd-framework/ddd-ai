<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The AI costs booked under one usage scope during one piece of work; an element of {@see AIUsageScopeBookedCosts}.
 */
class AIUsageScopeBookedCost extends ValueObject
{
    /** @var string The usage scope the costs were booked under */
    public string $usageScope = '';

    /** @var float The USD booked under the scope */
    public float $costInUSD = 0.0;

    public function __construct(string $usageScope = '', float $costInUSD = 0.0)
    {
        $this->usageScope = $usageScope;
        $this->costInUSD = $costInUSD;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->usageScope);
    }
}
