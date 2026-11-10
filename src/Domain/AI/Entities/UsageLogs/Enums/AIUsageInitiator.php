<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs\Enums;

use ReflectionClass;

/**
 * Identifies who/what initiated an AI call.
 *
 * This class helps categorize AI usage by origin for:
 * - Cost attribution (user vs. system)
 * - Rate limiting strategies
 * - Debugging and monitoring
 * - Billing segmentation
 */
class AIUsageInitiator
{
    /** User-initiated via UI or Client/Admin/Partner API */
    public const string ACCOUNT = 'ACCOUNT';

    /** Background system operations (internal processes) */
    public const string SYSTEM = 'SYSTEM';

    /** Console command execution (bin/console) */
    public const string CLI = 'CLI';

    /** Symfony Messenger async job processing */
    public const string MESSENGER = 'MESSENGER';

    /** External webhook trigger (e.g., Stripe, partner callbacks) */
    public const string WEBHOOK = 'WEBHOOK';

    /** Scheduled/cron job execution */
    public const string SCHEDULER = 'SCHEDULER';

    /**
     * Create from a string value with fallback
     *
     * @param string $value
     * @return string
     */
    public static function fromString(string $value): string
    {
        return match (strtoupper($value)) {
            'ACCOUNT' => self::ACCOUNT,
            'CLI' => self::CLI,
            'MESSENGER' => self::MESSENGER,
            'WEBHOOK' => self::WEBHOOK,
            'SCHEDULER' => self::SCHEDULER,
            default => self::SYSTEM,
        };
    }

    /**
     * Get all possible values for validation
     */
    public static function values(): array
    {
        $reflection = new ReflectionClass(self::class);
        $constants = $reflection->getConstants();

        return array_values(
            array_filter(
                $constants,
                static fn($value) => is_string($value),
            ),
        );
    }

    /**
     * Check if this initiator is user-facing (should count against user budget)
     */
    public static function isUserFacing(string $initiator): bool
    {
        // Non-user-facing initiators (SYSTEM, CLI, MESSENGER, SCHEDULER, ...) fall through to default
        return match ($initiator) {
            self::ACCOUNT, self::WEBHOOK => true,
            default => false,
        };
    }
}
