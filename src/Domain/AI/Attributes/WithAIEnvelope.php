<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Attribute for automatic AI envelope management.
 *
 * Can be applied at class level (all methods) or method level (specific methods).
 * Method-level attributes override class-level ones.
 *
 * Usage:
 * ```php
 * #[WithAIEnvelope(scope: 'ENVELOPE.SEO.TEXT.OPTIMIZATION')]
 * class MyController
 * {
 *     public function autoSetup() { ... }  // Has envelope
 *
 *     #[WithAIEnvelope(disabled: true)]
 *     public function noEnvelope() { ... } // Opt-out
 * }
 *
 * // Or method-level only:
 * class AnotherController
 * {
 *     #[WithAIEnvelope(scope: 'ENVELOPE.AI.CONVERSATION.TURN')]
 *     public function chat() { ... }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class WithAIEnvelope
{
    /** @var string Every envelope scope starts with this prefix; the app's scope catalog names the scopes. */
    public const string ENVELOPE_SCOPE_PREFIX = 'ENVELOPE.';

    /**
     * @param string $scope The envelope scope (must be an ENVELOPE.* scope)
     * @param string|null $initiatedBy Override the default initiator (defaults to ACCOUNT for HTTP, CLI for console)
     * @param bool $disabled Set to true to opt-out at method level when class has the attribute
     */
    public function __construct(
        public string $scope = '',
        public ?string $initiatedBy = null,
        public bool $disabled = false,
    ) {
        // Fail-fast validation at definition time
        if (!$this->disabled && $this->scope !== '' && !str_starts_with($this->scope, static::ENVELOPE_SCOPE_PREFIX)) {
            throw new InvalidArgumentException(
                "Scope '$this->scope' must be an ENVELOPE.* scope. " .
                "Use an envelope scope constant of the app's usage scope catalog, e.g. 'ENVELOPE.AI.CONVERSATION.TURN'."
            );
        }
    }
}
