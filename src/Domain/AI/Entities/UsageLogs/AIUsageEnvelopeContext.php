<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\UsageLogs;

use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One open AI usage envelope: the group every AI call made while it is open is attributed to
 * ({@see \DDD\Domain\AI\Services\AIUsageEnvelopesService}). The id is set when the envelope is opened — by the
 * application's usage log when it persists envelopes, else an id counted in the process.
 *
 * An application that attributes envelopes to more than an account (a project, a location) subclasses this object with
 * its own properties and opens the envelope with {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::openEnvelopeForContext()};
 * its override of {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService::persistEnvelopeOpening()} reads them.
 */
class AIUsageEnvelopeContext extends ValueObject
{
    /** @var int|null The envelope's id; null until the envelope is opened */
    public ?int $envelopeId = null;

    /** @var int|null The account the envelope's calls are billed to; null for work without an account */
    public ?int $accountId = null;

    /** @var string The envelope scope, always starting with `ENVELOPE.` ({@see \DDD\Domain\AI\Attributes\WithAIEnvelope::ENVELOPE_SCOPE_PREFIX}) */
    public string $scope = '';

    /** @var string Who started the work ({@see AIUsageInitiator}) */
    public string $initiatedBy = AIUsageInitiator::ACCOUNT;

    /**
     * @param int|null $accountId The account the envelope's calls are billed to
     * @param string $scope The envelope scope (`ENVELOPE.*`)
     * @param string $initiatedBy Who started the work ({@see AIUsageInitiator})
     * @return static A context not yet opened (no id)
     */
    public static function forScope(?int $accountId, string $scope, string $initiatedBy = AIUsageInitiator::ACCOUNT): static
    {
        $envelopeContext = new static();
        $envelopeContext->accountId = $accountId;
        $envelopeContext->scope = $scope;
        $envelopeContext->initiatedBy = $initiatedBy;
        return $envelopeContext;
    }
}
