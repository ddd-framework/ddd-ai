<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Repo\DB\UsageLogs\DBAIUsageLog;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseForeignKey;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The AI cost ledger: one row per booked AI cost (a priced model call, a slice of session seconds, a fee such as web
 * search requests). Written only by {@see AIUsageLogsService::recordUsage()}; the moment of the cost is `created`.
 *
 * Recording is opt-in: the module's usage recorder ({@see \DDD\Domain\AI\Services\AIModelCallUsageRecorderService})
 * books into this table when the config `AI.usageLog.enabled` is true (default false), so an application without the
 * table behaves as before. An application that attributes more (a tenant, a project, a conversation) adds columns with
 * a same-path subclass in `App\Domain\AI\Entities\UsageLogs` and overrides
 * {@see AIUsageLogsService::attributeUsageLog()}; the table name stays `AIUsageLogs`.
 *
 * No budget reads this table; it keeps what a later cap per account would need. Read rights
 * ({@see DBAIUsageLog::applyReadRightsQuery()}): an admin reads every row, any other account its own rows.
 *
 * @method static AIUsageLogsService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIUsageLog::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['feature', 'created'])]
class AIUsageLog extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int The longest stored usage scope or envelope scope */
    public const int SCOPE_MAX_LENGTH = 128;

    /** @var int|null The account the cost is attributed to: the billed account, else the envelope's, else the authenticated one */
    public ?int $accountId;

    /** @var Account|null The account the cost is attributed to */
    #[LazyLoad]
    #[DatabaseForeignKey(onDeleteAction: DatabaseForeignKey::ACTION_SET_NULL)]
    public ?Account $account;

    /**
     * @var string|null The grouping a cost report reads (an application's feature name). Default: the usage scope, else
     * the short class name of the repo ({@see AIUsageLogsService::findFeature()})
     */
    #[NotNull]
    #[Length(max: 32)]
    public ?string $feature;

    /** @var string|null The usage scope of the call; none for a call that declares none */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $usageScope;

    /** @var string|null The scope of the innermost usage envelope open while the cost was booked */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $envelopeScope;

    /** @var string|null What made the call: the short class name of the repo or service */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $sourceName;

    /** @var string|null The catalog name of the model; a retired model's call keeps the name that was asked for */
    #[Length(max: 64)]
    public ?string $aiModelName;

    /** @var string|null The registry prompt of the call */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $aiPromptName;

    /** @var int|null Input tokens, cached and cache-written ones included */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $inputTokens;

    /** @var int|null Output tokens, reasoning tokens included */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $outputTokens;

    /** @var int|null Input tokens read from the prompt cache */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $cachedInputTokens;

    /** @var int|null Input tokens written to the prompt cache */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $cacheWriteTokens;

    /** @var int|null Output tokens spent on reasoning */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $reasoningOutputTokens;

    /** @var int|null Web searches the call made or the fee covers */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $webSearchCount;

    /** @var float|null Seconds of a session billed by time (a realtime voice session) */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $sessionSeconds;

    /** @var float|null The cost in USD */
    #[NotNull]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $costInUSD;

    /** @var string|null Who started the work ({@see AIUsageInitiator}) */
    #[Length(max: 32)]
    #[Choice(callback: [AIUsageInitiator::class, 'values'])]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $initiatedBy;

    /**
     * @param string|null $scope
     * @return string|null The scope as stored (cut to SCOPE_MAX_LENGTH); null for an empty one
     */
    public static function normalizeScope(?string $scope): ?string
    {
        if ($scope === null || trim($scope) === '') {
            return null;
        }
        return mb_substr(trim($scope), 0, static::SCOPE_MAX_LENGTH);
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
