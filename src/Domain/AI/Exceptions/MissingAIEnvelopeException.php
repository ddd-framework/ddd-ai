<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Exception thrown when an AI operation is attempted without an active envelope context.
 *
 * This exception indicates a developer error - AI operations must be wrapped in an envelope
 * for proper tracking and aggregation of AI usage costs.
 */
class MissingAIEnvelopeException extends InternalErrorException
{
    public function __construct(string $className)
    {
        parent::__construct(
            "AI operation in '$className' requires an envelope context. " .
            'Add #[WithAIEnvelope] to controller/service method or use AIUsageEnvelopeManager.'
        );
    }
}
