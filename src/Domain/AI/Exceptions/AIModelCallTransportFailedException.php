<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The HTTP transfer of an agent model call failed — Guzzle's `TransferException`: no response at all (a connect timeout,
 * the read timeout of {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait::DEFAULT_ARGUS_TIMEOUT}, a dropped
 * connection) or a Guzzle response exception raised while the transfer ran. The Argus layer sends with
 * `http_errors: false`, so an HTTP error status normally arrives as a response the loop reads as a provider error
 * ({@see AIModelCallFailedException}, which a retry will not fix); what lands here is the transfer itself failing,
 * treated as transient: the worker turn releases it like the index-lock failure — one counted attempt, the handover
 * marker, re-driven by the owed-turn recovery pass
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease::afterModelCallTransportFailure()}). The Guzzle
 * exception rides as the previous one.
 */
class AIModelCallTransportFailedException extends RuntimeException
{
    public function __construct(Throwable $transportFailure)
    {
        parent::__construct('Model call transport failed: ' . $transportFailure->getMessage(), 0, $transportFailure);
    }
}
