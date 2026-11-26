<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Throwable;
use DDD\Infrastructure\Services\Service;

/**
 * Books what a model call consumed. {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait} calls it once per
 * answer whose cost is known, {@see AISessionUsageService} once per booked slice of session seconds.
 *
 * With the config `AI.usageLog.enabled` true it books every priced usage into the AI cost ledger
 * ({@see \DDD\Domain\AI\Entities\UsageLogs\AIUsageLog}, table AIUsageLogs) and reports the cost to the open usage
 * envelope ({@see AIUsageEnvelopesService::recordBookedCost()}). Default off: an application without the table behaves
 * as before. An application with budget pots of its own extends this class with a same-path subclass in
 * `App\Domain\AI\Services` and calls the parent first.
 */
class AIModelCallUsageRecorderService extends Service
{
    /**
     * Records the usage of one model call.
     */
    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): void
    {
        $costs = $aiModelCallUsage->costs ?? null;
        if ($costs === null || !$this->isUsageLogEnabled()) {
            return;
        }
        /** @var AIUsageLogsService $aIUsageLogsService */
        $aIUsageLogsService = DDDService::instance()->getService(AIUsageLogsService::class);
        try {
            $aIUsageLogsService->recordModelCallUsage($aiModelCall, $aiModelCallUsage);
        } catch (Throwable $ledgerWriteFailure) {
            // the call is answered and paid: a ledger that cannot write (table missing, database gone) is logged as an
            // error and never fails the work that caused the cost
            DDDService::instance()->getLogger()->error(
                '[AIUsageLog] the cost of ' . AIUsageLogsService::shortClassName($aiModelCall->argusClass) . ' (' . (float)$costs->amount
                . ' USD) was not booked: ' . $ledgerWriteFailure->getMessage(),
                ['exception' => $ledgerWriteFailure]
            );
        }
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        // a call that declares no usage scope is reported under its repo's short class name
        $aIUsageEnvelopesService->recordBookedCost(
            $aiModelCall->aiUsageScope ?? AIUsageLogsService::shortClassName($aiModelCall->argusClass),
            (float)$costs->amount
        );
    }

    /** Whether the ledger records (config `AI.usageLog.enabled`, default false) */
    public function isUsageLogEnabled(): bool
    {
        return (bool)Config::get(AIUsageLogsService::CONFIG_ENABLED);
    }
}
