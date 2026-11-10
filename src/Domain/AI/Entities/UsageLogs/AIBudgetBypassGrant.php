<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision that an AI usage envelope runs WITHOUT the account's AI-budget cap, and why. Decided once by the code
 * that knows the reason (a worker from a conversation's bypass flag, a console command from its budget option, an
 * admin request, system maintenance) and carried on the envelope
 * ({@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::grantBudgetBypassForCurrentEnvelope()}); every envelope
 * opened inside it inherits it. A console command, which reads its options before any envelope is open, grants for
 * the whole process instead
 * ({@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::grantBudgetBypassForTheProcess()}). The spend is always
 * booked — the grant lifts the cap only.
 */
class AIBudgetBypassGrant extends ValueObject
{
    /** The conversation carries `budgetBypass` (set by an admin): its turns, tools and background work. */
    public const string REASON_CONVERSATION_BYPASS = 'CONVERSATION_BYPASS';

    /** A console command run with its budget option. */
    public const string REASON_CONSOLE_OPTION = 'CONSOLE_OPTION';

    /** A REST request that asked for the bypass, made by an admin session. */
    public const string REASON_ADMIN_REQUEST = 'ADMIN_REQUEST';

    /** System maintenance the customer never asked for. */
    public const string REASON_SYSTEM_MAINTENANCE = 'SYSTEM_MAINTENANCE';

    /** @var string Why the cap is lifted, one of the REASON_ constants */
    public string $reason = self::REASON_CONVERSATION_BYPASS;

    public static function forReason(string $reason): static
    {
        $budgetBypassGrant = new static();
        $budgetBypassGrant->reason = $reason;
        return $budgetBypassGrant;
    }
}
