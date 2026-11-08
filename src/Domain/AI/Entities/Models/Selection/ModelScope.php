<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

/**
 * What an AI model is selected FOR — the task family, one level above {@see ModelTier}. Each scope carries its own
 * selection priorities over the model properties (quality score, speed, cost) and capability requirements; the
 * mapping scope → policy lives in {@see \DDD\Domain\AI\Services\AIModelsService} (config-tunable). String
 * constants (matching the AIModel::* / ModelTier style), so a scope keys config + the selection cache directly. Not
 * final — extensible per the project's visibility/extensibility rule.
 */
class ModelScope
{
    /** The live conversational agent: tool-calling REQUIRED, interactive (speed matters), routing quality first. */
    public const string AGENTIC = 'AGENTIC';

    /** Conversation-history compaction summarizer: no tool-calling, async/background (speed secondary), cost-dominant. */
    public const string COMPACTION = 'COMPACTION';

    /** Durable-memory curation (extract / reconcile facts): no tool-calling, structured-output quality + cost-dominant. */
    public const string MEMORY_MANAGEMENT = 'MEMORY_MANAGEMENT';

    /** Latency-critical, user-blocking, NON-agentic reads — the search/memory rerank: no tool-calling,
     *  SPEED objective, cheap. Resolves to the fastest cheap model (e.g. gpt-oss-120b, provider-pinned to Cerebras). */
    public const string FAST_CHEAP = 'FAST_CHEAP';

    /** General LLM summarization — a quality-leaning STANDARD-tier one-off (e.g. the product-aware agent's per-
     *  ProductRatePlan / per-FeatureFlag descriptions, cached 24h): non-interactive, no tool-calling, low volume, so it
     *  sits a tier ABOVE the cost-dominant COMPACTION summarizer (CHEAP/COST). CAPABILITY over the STANDARD band. */
    public const string SUMMARIZATION = 'SUMMARIZATION';

    /** Conversation ORCHESTRATOR / supervisor checkpoints (an agent supervisor): async, briefing-in/JSON-out,
     * quality-dominant — must out-think the turn models, so it selects by CAPABILITY over the STANDARD band. */
    public const string ORCHESTRATOR = 'ORCHESTRATOR';

    /** @var array<int, string> All scopes. */
    public const array ALL = [
        self::AGENTIC,
        self::COMPACTION,
        self::MEMORY_MANAGEMENT,
        self::FAST_CHEAP,
        self::SUMMARIZATION,
        self::ORCHESTRATOR,
    ];

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }
}
