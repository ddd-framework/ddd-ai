<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

/**
 * The in-band optimization objective a tier uses to pick among its Pareto-frontier qualifiers.
 * String constants, config-keyed. Not final — extensible.
 *
 *  - CAPABILITY: highest agentic-intelligence score (cost as tie-break) — PREMIUM.
 *  - COST:       cheapest blended $/1M above the floor (value-per-$ as tie-break) — STANDARD / CHEAP.
 *  - VALUE:      best agentic-score-per-blended-$ (intelligence per dollar).
 */
class ModelObjective
{
    public const string CAPABILITY = 'CAPABILITY';

    public const string COST = 'COST';

    public const string VALUE = 'VALUE';

    /** Latency-first: rank by speed (tokensPerSecond / TTFT), cost as the tie-break. For non-agentic, user-blocking
     *  reads (search/memory rerank) where the model just classifies fast — see ModelScope::FAST_CHEAP. */
    public const string SPEED = 'SPEED';

    /** @var string[] */
    public const array ALL = [self::CAPABILITY, self::COST, self::VALUE, self::SPEED];

    public static function isValid(string $objective): bool
    {
        return in_array($objective, self::ALL, true);
    }
}
