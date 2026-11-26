<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;

/** The module's usage recorder with the ledger switched on (`AI.usageLog.enabled` true) */
class LedgerEnabledAIModelCallUsageRecorderService extends AIModelCallUsageRecorderService
{
    public function isUsageLogEnabled(): bool
    {
        return true;
    }
}
