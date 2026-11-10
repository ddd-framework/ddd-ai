<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Exceptions;

use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Tests\AI\Support\ApplicationAIScopeNotEligibleException;
use PHPUnit\Framework\TestCase;

class AIScopeNotEligibleExceptionTest extends TestCase
{
    public function testTheRefusalNamesStateAndReasonAndCarriesTheWireCode(): void
    {
        $exception = AIScopeNotEligibleException::forEligibilityState('LOCKED', 'PAYMENT_OVERDUE');

        $this->assertInstanceOf(ForbiddenException::class, $exception);
        $this->assertSame(403, $exception->getCode());
        $this->assertSame('AI operation refused: the scope is locked (payment overdue).', $exception->getMessage());
        $this->assertSame('AI_SCOPE_NOT_ELIGIBLE', $exception->errorCode);
        $this->assertSame('LOCKED', $exception->eligibilityState);
        $this->assertSame('PAYMENT_OVERDUE', $exception->eligibilityReason);
    }

    public function testASubclassGetsAnInstanceOfItselfThatTheModuleCatchStillMatches(): void
    {
        $exception = ApplicationAIScopeNotEligibleException::forEligibilityState('DELETED', null);

        $this->assertInstanceOf(ApplicationAIScopeNotEligibleException::class, $exception);
        $this->assertInstanceOf(AIScopeNotEligibleException::class, $exception);
        $this->assertSame('AI operation refused: the scope is deleted ().', $exception->getMessage());
        $this->assertNull($exception->eligibilityReason);
    }
}
