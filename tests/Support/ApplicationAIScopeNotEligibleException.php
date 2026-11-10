<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;

/** An application's same-path subclass, as the application's gate throws it */
class ApplicationAIScopeNotEligibleException extends AIScopeNotEligibleException
{
}
