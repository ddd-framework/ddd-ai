<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\UsageLogs;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageLog;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * Writes and scopes the AI cost ledger ({@see AIUsageLog}).
 *
 * Writers: {@see recordModelCallUsage()} (the module's usage recorder hands every priced model call and every booked
 * slice of session seconds here when `AI.usageLog.enabled` is true) and {@see recordFee()} (a cost that is no model
 * call, e.g. the requests of a web search). Both fill the attribution with {@see attributeUsageLog()} and store the
 * row with {@see recordUsage()}, which lifts the rights restrictions for that one write. A row that cannot be stored
 * throws: a cost is never dropped in silence.
 *
 * Override points of an application (same-path subclass in `App\Domain\AI\Services\UsageLogs`):
 * - {@see attributeUsageLog()}: more columns (a tenant, a conversation from {@see AIModelCall::$subjectEntity});
 * - {@see findFeature()}: the application's feature names;
 * - {@see findAccountIdConfiningTheReader()}: who reads whose costs.
 */
class AIUsageLogsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIUsageLog::class;

    /** @var string The config key that switches recording on (default off) */
    public const string CONFIG_ENABLED = 'AI.usageLog.enabled';

    /**
     * Books one priced model call (or slice of session seconds). A usage without costs books nothing.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function recordModelCallUsage(AIModelCall $aiModelCall, AIModelCallUsage $aiModelCallUsage): ?AIUsageLog
    {
        $costs = $aiModelCallUsage->costs ?? null;
        if ($costs === null) {
            return null;
        }
        $aIUsageLog = $this->createUsageLog();
        $aIUsageLog->costInUSD = (float)$costs->amount;
        $aIUsageLog->usageScope = AIUsageLog::normalizeScope($aiModelCall->aiUsageScope);
        $aIUsageLog->sourceName = mb_substr(static::shortClassName($aiModelCall->argusClass), 0, 128);
        $aiModel = $aiModelCall->aiModel;
        // a retired model's call is filed under the name that was asked for, never under its successor
        $aIUsageLog->aiModelName = $aiModel !== null ? (mb_substr((string)($aiModel->resolvedFromRetiredModelName ?? $aiModel->name ?? ''), 0, 64) ?: null) : null;
        $aIUsageLog->aiPromptName = $aiModelCall->aiPrompt !== null ? (mb_substr((string)($aiModelCall->aiPrompt->name ?? ''), 0, 128) ?: null) : null;
        $aIUsageLog->inputTokens = $aiModelCallUsage->inputTokens;
        $aIUsageLog->outputTokens = $aiModelCallUsage->outputTokens;
        $aIUsageLog->cachedInputTokens = $aiModelCallUsage->cachedInputTokens ?: null;
        $aIUsageLog->cacheWriteTokens = $aiModelCallUsage->cacheWriteTokens ?: null;
        $aIUsageLog->reasoningOutputTokens = $aiModelCallUsage->reasoningOutputTokens ?: null;
        $aIUsageLog->webSearchCount = $aiModelCallUsage->webSearchCallCount ?: null;
        $aIUsageLog->sessionSeconds = $aiModelCallUsage->sessionSeconds > 0 ? $aiModelCallUsage->sessionSeconds : null;
        $this->attributeUsageLog(
            $aIUsageLog,
            $aiModelCall->accountBilledForAICosts,
            $aiModelCall->entityWithinAccountBilledForAICosts,
            $aiModelCall->subjectEntity,
            $aiModelCall->argusClass
        );
        return $this->recordUsage($aIUsageLog);
    }

    /**
     * Books a cost that is no model call (the requests of a web search). A fee of 0 books nothing.
     *
     * @param string $usageScope
     * @param float $costInUSD
     * @param string $sourceName What charged the fee (a short class name)
     * @param Account|null $account The account that pays, when known
     * @param Entity|null $billedEntity The entity within the account the work ran for, when known
     * @param int|null $webSearchCount The searches the fee covers
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function recordFee(string $usageScope, float $costInUSD, string $sourceName, ?Account $account, ?Entity $billedEntity = null, ?int $webSearchCount = null): ?AIUsageLog
    {
        if ($costInUSD <= 0.0) {
            return null;
        }
        $aIUsageLog = $this->createUsageLog();
        $aIUsageLog->costInUSD = $costInUSD;
        $aIUsageLog->usageScope = AIUsageLog::normalizeScope($usageScope);
        $aIUsageLog->sourceName = mb_substr($sourceName, 0, 128);
        $aIUsageLog->webSearchCount = $webSearchCount;
        $this->attributeUsageLog($aIUsageLog, $account, $billedEntity, null, null);
        return $this->recordUsage($aIUsageLog);
    }

    /**
     * Stores one row as the system (the rights restrictions are lifted for this one write).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function recordUsage(AIUsageLog $aIUsageLog): AIUsageLog
    {
        $dddService = DDDService::instance();
        $dddService->deactivateEntityRightsRestrictions();
        try {
            /** @var AIUsageLog $storedAIUsageLog */
            $storedAIUsageLog = $this->update($aIUsageLog);
            return $storedAIUsageLog;
        } finally {
            $dddService->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The account whose rows the authenticated account may read: null = every row (an admin with access to all
     * resources), its own id for any other account, 0 = no row (nobody authenticated).
     */
    public function findAccountIdConfiningTheReader(): ?int
    {
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount === null || !isset($authAccount->id)) {
            return 0;
        }
        if ($authAccount->isAdminWithAccessToAllResources()) {
            return null;
        }
        return (int)$authAccount->id;
    }

    /**
     * Fills the attribution of a row: the account (billed, else the open envelope's, else the authenticated one), the
     * envelope scope, who started the work, the feature. An application adds its own columns in an override and calls
     * this method first.
     *
     * @param AIUsageLog $aIUsageLog
     * @param Account|null $billedAccount
     * @param Entity|null $billedEntity The entity within the billed account (a tenant, a project)
     * @param Entity|null $subjectEntity The record the call worked for ({@see AIModelCall::$subjectEntity})
     * @param string|null $argusClass The repo that made the call; null for a fee
     */
    protected function attributeUsageLog(AIUsageLog $aIUsageLog, ?Account $billedAccount, ?Entity $billedEntity, ?Entity $subjectEntity, ?string $argusClass): void
    {
        $aIUsageLog->accountId = $billedAccount !== null && isset($billedAccount->id) ? (int)$billedAccount->id : null;
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $envelopeContext = $aIUsageEnvelopesService->getCurrentEnvelopeContext();
        $aIUsageLog->envelopeScope = AIUsageLog::normalizeScope($envelopeContext?->scope);
        $aIUsageLog->accountId ??= $envelopeContext?->accountId;
        $authAccount = AuthService::instance()->getAccount();
        $aIUsageLog->accountId ??= $authAccount !== null && isset($authAccount->id) ? (int)$authAccount->id : null;
        $aIUsageLog->initiatedBy = $envelopeContext?->initiatedBy ?? (PHP_SAPI === 'cli' ? AIUsageInitiator::CLI : AIUsageInitiator::ACCOUNT);
        $aIUsageLog->feature = $this->findFeature($aIUsageLog, $argusClass);
    }

    /**
     * The grouping of a row. Default: the usage scope, else the short class name of the repo, else OTHER (cut to the
     * column). An application maps both to its own feature names.
     */
    protected function findFeature(AIUsageLog $aIUsageLog, ?string $argusClass): string
    {
        $feature = $aIUsageLog->usageScope ?? ($argusClass !== null ? static::shortClassName($argusClass) : 'OTHER');
        return mb_substr($feature, 0, 32);
    }

    /** A new row of the entity class the application uses (a same-path subclass when it has one) */
    protected function createUsageLog(): AIUsageLog
    {
        /** @var AIUsageLog $aIUsageLog */
        $aIUsageLog = static::getEntityClassInstance();
        return $aIUsageLog;
    }

    /** @return string The class name without its namespace */
    public static function shortClassName(string $className): string
    {
        $separatorPosition = strrpos($className, '\\');
        return $separatorPosition === false ? $className : substr($className, $separatorPosition + 1);
    }
}
