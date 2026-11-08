<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;

/** A usage recorder that keeps every recorded usage */
class RecordingAIModelCallUsageRecorderService extends AIModelCallUsageRecorderService
{
    /** @var AIModelCallUsage[] */
    public array $recordedUsages = [];

    /** @var AIModelCall[] */
    public array $recordedCalls = [];

    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): void
    {
        $this->recordedCalls[] = $aiModelCall;
        $this->recordedUsages[] = $aiModelCallUsage;
    }
}
