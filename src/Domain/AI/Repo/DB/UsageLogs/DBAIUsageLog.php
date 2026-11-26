<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\UsageLogs;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageLog;
use DDD\Domain\AI\Services\UsageLogs\AIUsageLogsService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIUsageLog find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AIUsageLog update(DefaultObject &$entity, int $depth = 1)
 * @property DBAIUsageLogModel $ormInstance
 */
class DBAIUsageLog extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIUsageLog::class;

    public const string BASE_ORM_MODEL = DBAIUsageLogModel::class;

    /**
     * The account whose rows the reader may read ({@see AIUsageLogsService::findAccountIdConfiningTheReader()}): none =
     * every row, an id = that account's rows, 0 = no row. An application overrides the service method (e.g. a
     * super admin reads all rows, another admin its own), not this query.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AIUsageLogsService $aIUsageLogsService */
        $aIUsageLogsService = AIUsageLog::getService();
        $confiningAccountId = $aIUsageLogsService->findAccountIdConfiningTheReader();
        if ($confiningAccountId === null) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.accountId = :{$baseModelAlias}_aiUsageOwnAccountId")
            ->setParameter($baseModelAlias . '_aiUsageOwnAccountId', $confiningAccountId);
        return true;
    }

    /**
     * No account writes, changes or deletes a row with its own rights: the one writer is
     * {@see AIUsageLogsService::recordUsage()}, which stores the row with the rights restrictions lifted.
     */
    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.id is null");
        return true;
    }

    public static function applyCreateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyUpdateRightsQuery($queryBuilder);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyUpdateRightsQuery($queryBuilder);
    }
}
