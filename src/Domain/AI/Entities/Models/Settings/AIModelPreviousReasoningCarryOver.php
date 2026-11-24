<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Settings;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Whether and how far an agent sends a model's own reasoning of earlier calls back on the later calls of a conversation
 * (Chat Completions `reasoning_details`, Responses `reasoning` items, Messages `thinking` blocks) — the policy of one
 * model in the agentic use case ({@see AIModelAgenticUseCaseConfig::$previousReasoning}, catalog key
 * `agenticUseCase.previousReasoning`). Every field is optional: null = the agent's next source decides (the
 * conversation's run settings come first, the agent's config per wire API and its built-in default after this).
 *
 * The cut of `capped`, in reasoning tokens: when the reasoning the calls carry exceeds the larger of `capTokens` and
 * the reasoning kept at the last cut plus `headroomTokensAfterCut`, the older steps lose their reasoning in one block;
 * walking back from the newest step, whole steps are kept until at least `keptTokensAtCut` reasoning tokens and at
 * least `keptStepsAtCut` steps are kept.
 */
class AIModelPreviousReasoningCarryOver extends ValueObject
{
    /** The model's own reasoning of a previous call is never sent back */
    public const string CARRY_OVER_OFF = 'off';

    /** Only the reasoning of the current owner turn's earlier calls is sent back */
    public const string CARRY_OVER_CURRENT_TURN = 'currentTurn';

    /** The reasoning of earlier calls is sent back up to a token cap, then cut in one block */
    public const string CARRY_OVER_CAPPED = 'capped';

    /** Every earlier call's reasoning is sent back (it leaves only with a compaction) */
    public const string CARRY_OVER_ALL = 'all';

    /** @var string[] The values {@see $carryOver} accepts */
    public const array CARRY_OVERS = [
        self::CARRY_OVER_OFF,
        self::CARRY_OVER_CURRENT_TURN,
        self::CARRY_OVER_CAPPED,
        self::CARRY_OVER_ALL,
    ];

    /** @var string|null One of {@see CARRY_OVERS}; null = not decided by the model */
    public ?string $carryOver = null;

    /** @var int|null Under `capped`: above this many carried reasoning tokens the older steps lose their reasoning (max) */
    public ?int $capTokens = null;

    /** @var int|null Under `capped`: at a cut, whole steps are kept from the newest back until at least this many reasoning tokens (min) */
    public ?int $keptTokensAtCut = null;

    /** @var int|null Under `capped`: at a cut, at least this many of the newest steps keep their reasoning */
    public ?int $keptStepsAtCut = null;

    /** @var int|null Under `capped`: after a cut, the next cut waits until the carried sum exceeds the kept reasoning plus this many tokens */
    public ?int $headroomTokensAfterCut = null;

    public function __construct(
        ?string $carryOver = null,
        ?int $capTokens = null,
        ?int $keptTokensAtCut = null,
        ?int $keptStepsAtCut = null,
        ?int $headroomTokensAfterCut = null
    ) {
        $this->carryOver = $carryOver;
        $this->capTokens = $capTokens;
        $this->keptTokensAtCut = $keptTokensAtCut;
        $this->keptStepsAtCut = $keptStepsAtCut;
        $this->headroomTokensAfterCut = $headroomTokensAfterCut;
        parent::__construct();
    }

    /**
     * The policy of a catalog block (`agenticUseCase.previousReasoning`); a value of the wrong kind or range reads as
     * not set. Null when the block sets nothing valid.
     *
     * @param array<string, mixed> $previousReasoningConfig
     */
    public static function fromCatalogArray(array $previousReasoningConfig): ?static
    {
        $carryOver = $previousReasoningConfig['carryOver'] ?? null;
        $aiModelPreviousReasoningCarryOver = new static(
            is_string($carryOver) && in_array($carryOver, self::CARRY_OVERS, true) ? $carryOver : null,
            self::readCount($previousReasoningConfig['capTokens'] ?? null, 1),
            self::readCount($previousReasoningConfig['keptTokensAtCut'] ?? null, 0),
            self::readCount($previousReasoningConfig['keptStepsAtCut'] ?? null, 1),
            self::readCount($previousReasoningConfig['headroomTokensAfterCut'] ?? null, 0),
        );
        return $aiModelPreviousReasoningCarryOver->isEmpty() ? null : $aiModelPreviousReasoningCarryOver;
    }

    /** Whether no field is set */
    public function isEmpty(): bool
    {
        return $this->getCarryOver() === null && $this->capTokens === null && $this->keptTokensAtCut === null
            && $this->keptStepsAtCut === null && $this->headroomTokensAfterCut === null;
    }

    /** The carry-over mode (one of {@see CARRY_OVERS}), null when unset or unknown */
    public function getCarryOver(): ?string
    {
        return in_array($this->carryOver, self::CARRY_OVERS, true) ? $this->carryOver : null;
    }

    /** An integer of at least `$minimum` (an integer-valued number counts), else null */
    protected static function readCount(mixed $value, int $minimum): ?int
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int)$value;
        }
        return is_int($value) && $value >= $minimum ? $value : null;
    }
}
