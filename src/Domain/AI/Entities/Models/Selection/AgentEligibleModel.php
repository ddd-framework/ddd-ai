<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One AIModel the agent may run on — a language model that is tool-calling capable, agent-eligible, and rated
 * ({@see \DDD\Domain\AI\Services\AIModelsService::getAgentEligibleModels()}). The element type of
 * {@see AgentEligibleModels}. Carries just what the admin model picker needs to label a choice (name + its
 * {@see ModelTier} + the agentic intelligence score + speed + blended cost), NOT the full {@see \DDD\Domain\AI\Entities\Models\AIModel}.
 *
 * @method AgentEligibleModels getParent()
 */
class AgentEligibleModel extends ValueObject
{
    /** @var string The AIModel name / id, e.g. 'GOOGLE.GEMINI_2_5_FLASH' — what the drive endpoints accept as aiModelName. */
    public string $name;

    /** @var string|null The vendor (AIModel::VENDOR_* — OPENAI / GOOGLE / …), or null if unknown. */
    public ?string $vendor = null;

    /** @var string|null The agent tier this model is curated into (ModelTier::CHEAP|STANDARD|PREMIUM), or null if untiered. */
    public ?string $tier = null;

    /** @var int|null Agentic intelligence score (higher = more capable); null only when the filter is loosened (normally set). */
    public ?int $agenticScore = null;

    /** @var int|null Relative speed score (higher = faster), or null if unrated. */
    public ?int $speedScore = null;

    /** @var float|null Output throughput in tokens/sec (tps) — higher = faster generation; null if unmeasured. */
    public ?float $tokensPerSecond = null;

    /** @var int|null Time to first token (ttft) in milliseconds — lower = snappier; null if unmeasured. */
    public ?int $timeToFirstTokenMs = null;

    /** @var float|null Blended $/1M tokens (70% input / 30% output), rounded — for a cost hint in the picker. */
    public ?float $blendedCostPer1MUsd = null;

    /** @var int|null The model's HARD context window in tokens — the admin caps the compaction threshold at ~80% of it. */
    public ?int $contextWindowTokens = null;

    /** @var int|null The model's compaction threshold in tokens (reliable-reasoning length) — the admin auto-fills the token-threshold input with this when the model is picked. */
    public ?int $effectiveContextTokens = null;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
    }

    /** The model name is the identity, so {@see AgentEligibleModels} dedups by name. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
