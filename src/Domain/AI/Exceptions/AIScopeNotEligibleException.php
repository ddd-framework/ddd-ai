<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions;

use DDD\Infrastructure\Exceptions\ForbiddenException;

/**
 * The account or project a model call is billed to is NOT eligible for automated AI work (locked, deleted, cancelled,
 * erased — the states are the application's). Thrown by an application's
 * {@see \DDD\Domain\AI\Services\AIModelCallGateService} BEFORE the budget check and independent of it — a sibling of
 * {@see AiBudgetExceededException} with its own stable wire code, so a caller that catches the budget exception catches
 * this one too and ends with the suppressed outcome, while a client can tell "no budget" from "the account is not
 * active". Callers match on the class or on {@see self::ERROR_CODE}, never on the message.
 */
class AIScopeNotEligibleException extends ForbiddenException
{
    /** @var string The stable wire code for "the scope is not eligible for AI work" — match on THIS, never on the message. */
    public const string ERROR_CODE = 'AI_SCOPE_NOT_ELIGIBLE';

    /** @var string Machine-readable error code, serialized into the JSON error response alongside `error`. */
    public string $errorCode = self::ERROR_CODE;

    /** @var string|null The eligibility state that refused (the application's state constant, e.g. `LOCKED`). */
    public ?string $eligibilityState = null;

    /** @var string|null The reason behind the state (the application's reason constant). */
    public ?string $eligibilityReason = null;

    /**
     * The refusal for one eligibility state and its reason; the message names both in lower case
     * ("AI operation refused: the scope is locked (payment overdue).").
     *
     * @param string $eligibilityState The state that refused
     * @param string|null $eligibilityReason The reason behind the state, null when none is known
     * @return static
     */
    public static function forEligibilityState(string $eligibilityState, ?string $eligibilityReason): static
    {
        $exception = new static(sprintf(
            'AI operation refused: the scope is %s (%s).',
            strtolower($eligibilityState),
            strtolower(str_replace('_', ' ', (string)$eligibilityReason))
        ));
        $exception->eligibilityState = $eligibilityState;
        $exception->eligibilityReason = $eligibilityReason;
        return $exception;
    }
}
