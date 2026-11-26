<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\UsageLogs;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageLogs;
use DDD\Domain\Base\Repo\DB\DBEntitySet;

class DBAIUsageLogs extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIUsageLog::class;

    public const string BASE_ENTITY_SET_CLASS = AIUsageLogs::class;
}
