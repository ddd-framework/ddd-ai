<?php

declare(strict_types=1);

/**
 * The AI cost ledger (DDD\Domain\AI\Entities\UsageLogs\AIUsageLog, table AIUsageLogs).
 * `enabled`: the module's usage recorder books every priced model call and slice of session seconds into the table.
 * Default off; an application switches it on in its own config/app/AI/usageLog.php once the table exists.
 */
return [
    'enabled' => false,
];
