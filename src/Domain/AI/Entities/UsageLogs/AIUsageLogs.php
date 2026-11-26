<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\AI\Repo\DB\UsageLogs\DBAIUsageLogs;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Rows of the AI cost ledger ({@see AIUsageLog}).
 *
 * @property AIUsageLog[] $elements;
 * @method AIUsageLog getByUniqueKey(string $uniqueKey)
 * @method AIUsageLog first()
 * @method static AIUsageLogsService getService()
 * @method AIUsageLog[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIUsageLogs::class)]
#[QueryOptions]
class AIUsageLogs extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIUsageLogsService::class;
}
