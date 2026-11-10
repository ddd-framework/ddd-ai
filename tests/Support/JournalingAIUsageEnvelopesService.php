<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use RuntimeException;

/** An application's subclass: persists into a journal instead of a usage log, and can be told to fail */
class JournalingAIUsageEnvelopesService extends AIUsageEnvelopesService
{
    /** @var string[] */
    public array $journal = [];

    public bool $failsOnOpening = false;

    public bool $failsOnClosing = false;

    protected int $nextPersistedEnvelopeId = 1000;

    protected function persistEnvelopeOpening(AIUsageEnvelopeContext $envelopeContext): int
    {
        if ($this->failsOnOpening) {
            throw new RuntimeException('usage log unavailable');
        }
        $projectSuffix = $envelopeContext instanceof ProjectAIUsageEnvelopeContext ? " project {$envelopeContext->projectId}" : '';
        $this->journal[] = "open {$envelopeContext->scope}{$projectSuffix}";
        return $this->nextPersistedEnvelopeId++;
    }

    protected function persistEnvelopeClosing(AIUsageEnvelopeContext $envelopeContext): void
    {
        $this->journal[] = "close {$envelopeContext->envelopeId}";
        if ($this->failsOnClosing) {
            throw new RuntimeException('usage log unavailable');
        }
    }
}
