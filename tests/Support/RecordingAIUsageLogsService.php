<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageLog;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use RuntimeException;

/** A ledger that keeps the booked calls in memory, and can be told to fail like a missing table */
class RecordingAIUsageLogsService extends AIUsageLogsService
{
    /** @var string[] The argus class of every booked call */
    public array $bookedSourceClasses = [];

    public bool $failsOnWrite = false;

    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): ?AIUsageLog
    {
        if ($this->failsOnWrite) {
            throw new RuntimeException('Table AIUsageLogs does not exist');
        }
        $this->bookedSourceClasses[] = $aiModelCall->argusClass;
        return null;
    }

    public function callFindFeature(AIUsageLog $aIUsageLog, ?string $argusClass): string
    {
        return $this->findFeature($aIUsageLog, $argusClass);
    }
}
