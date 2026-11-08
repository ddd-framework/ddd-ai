<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Infrastructure\Services\Service;

/**
 * Books what a model call consumed. {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait} calls it once per
 * answer whose cost is known; this default records nothing, so an application without its own ledger behaves as before.
 *
 * An application books costs (a cost ledger, a per-call usage log) with a subclass of the same name in its own
 * `App\Domain\AI\Services` namespace; the framework's service lookup returns that subclass.
 */
class AIModelCallUsageRecorderService extends Service
{
    /**
     * Records the usage of one model call. Default: nothing.
     */
    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): void
    {
    }
}
