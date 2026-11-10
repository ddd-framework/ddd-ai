<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions;

use RuntimeException;

/**
 * The model call itself FAILED — the provider/proxy returned an ERROR body (e.g. a Google 400 INVALID_ARGUMENT on a
 * malformed tool schema, a rate-limit, an auth error) instead of a completion. This is NOT a stall (an empty/prose turn
 * the loop nudges) and NOT a lost run-claim: the request never produced a model response. Thrown from
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService}'s loop AFTER it persists a `MESSAGE_ERROR`
 * carrying the provider error body (so it is inspect-visible by default, no `--store-snapshot` needed), and CAUGHT in
 * the drive's try/catch which fails the conversation FAST: runState=FAILED, a STATUS_FAILED realtime signal, and NO
 * rethrow — a deterministic provider error (a 400 schema rejection) will not fix itself, so no Messenger redelivery and
 * no `resumeAttempts` retry. A plain RuntimeException, never a DDD HTTP exception. The customer-facing "there was an
 * error" message is the frontend's job (it renders it from runState=FAILED); the admin sees the full error body.
 */
class AIModelCallFailedException extends RuntimeException
{
}
