<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::grantBudgetBypassUntilRestored()} changed: the envelope
 * that got the grant and the grant it carried before (null = none). Handed back to
 * {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::restoreBudgetBypassGrant()} in a `finally`.
 */
class AIBudgetBypassGrantRestore extends ValueObject
{
    /** @var int The envelope the grant was set on */
    public int $envelopeId;

    /** @var AIBudgetBypassGrant|null The grant the envelope carried before; null when it carried none */
    public ?AIBudgetBypassGrant $priorBudgetBypassGrant = null;

    /**
     * @param int $envelopeId The envelope the grant was set on
     * @param AIBudgetBypassGrant|null $priorBudgetBypassGrant The grant it carried before, null for none
     * @return static
     */
    public static function forEnvelope(int $envelopeId, ?AIBudgetBypassGrant $priorBudgetBypassGrant): static
    {
        $budgetBypassGrantRestore = new static();
        $budgetBypassGrantRestore->envelopeId = $envelopeId;
        $budgetBypassGrantRestore->priorBudgetBypassGrant = $priorBudgetBypassGrant;
        return $budgetBypassGrantRestore;
    }
}
