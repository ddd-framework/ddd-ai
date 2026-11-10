<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;

/** An application's context subclass: attributes envelopes to a project as well */
class ProjectAIUsageEnvelopeContext extends AIUsageEnvelopeContext
{
    public ?int $projectId = null;
}
