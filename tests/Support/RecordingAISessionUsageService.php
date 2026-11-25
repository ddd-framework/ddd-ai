<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Services\AIModelCallUsageRecorderService;
use DDD\Domain\AI\Services\AISessionUsageService;

/** The session usage service with a recording usage recorder in place of the container's */
class RecordingAISessionUsageService extends AISessionUsageService
{
    public RecordingAIModelCallUsageRecorderService $recordingAIModelCallUsageRecorderService;

    public function __construct()
    {
        $this->recordingAIModelCallUsageRecorderService = new RecordingAIModelCallUsageRecorderService();
    }

    protected function getAIModelCallUsageRecorderService(): AIModelCallUsageRecorderService
    {
        return $this->recordingAIModelCallUsageRecorderService;
    }
}
