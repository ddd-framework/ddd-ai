<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * Exception thrown when an AI operation is attempted without a valid scope.
 *
 * This exception indicates a developer error - AI operations must have a scope
 * defined for proper categorization and tracking of AI usage.
 */
class MissingAIScopeException extends InternalErrorException
{
    public function __construct(string $className)
    {
        parent::__construct(
            "AI operation in '$className' requires a scope. " .
            "Add 'aiUsageScope' to the #[ArgusLanguageModel] attribute."
        );
    }
}
