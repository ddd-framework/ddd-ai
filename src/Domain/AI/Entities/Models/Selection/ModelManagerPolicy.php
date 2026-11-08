<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Declarative model-selection policy — the knobs {@see \DDD\Domain\AI\Services\AIModelsService} honors
 * when picking a model from the scored catalog: preferred tier band, soft vendor preference, optional cost ceiling,
 * interactivity, tool-calling requirement, and whether the curated agent-loop classification constrains the choice.
 * A DDD ValueObject (freely constructable, incl. unit tests).
 *
 * NO `autoMode` here — ADAPTIVE / runtime tier control is out of P0 scope (plan §5, separate flagged phase).
 */
class ModelManagerPolicy extends ValueObject
{
    /** @var string The operating band, a {@see ModelTier}::* constant. */
    public string $preferredTier;

    /** @var string|null Soft vendor preference (an AIModel::VENDOR_* value); never breaches the capability floor. */
    public ?string $preferredVendor;

    /** @var float|null Optional hard ceiling on blended $/1M tokens. */
    public ?float $maxBlendedCostPer1MUsd;

    /** @var bool When true, a minimum speed score may be enforced (interactive/chat use-case). */
    public bool $interactive;

    /**
     * @var bool Whether the selected model MUST support native tool-calling (the agentic default). Set false for
     *      NON-agentic scopes (compaction summary, memory/conversation-search rerank) where the model never calls
     *      a tool — otherwise the tool-calling hard gate wrongly excludes fast non-tool models.
     */
    public bool $requiresToolCalling;

    /**
     * @var bool Whether the curated agent-loop classification (the per-model `agentTier` membership AND the
     *      `agentEligible` gate) constrains selection. True for every AGENTIC/background scope (selection stays
     *      within the hand-vetted tier band). Set FALSE only for the FAST_CHEAP scope: a non-agentic speed pick
     *      MUST be able to reach agent-INELIGIBLE models (e.g. gpt-oss-120b @ Cerebras ≈526 tok/s — disqualified
     *      from the agent loop by its tool-calling channel leak, but the fastest cheap text generator we route to).
     *      When false, the agentTier filter + agentEligible gate are skipped and {@see $maxBlendedCostPer1MUsd}
     *      becomes the "cheap" boundary instead of tier membership.
     */
    public bool $appliesAgentLoopClassification;

    /**
     * @var bool Whether the `agentEligible` gate constrains selection — DECOUPLED from {@see $appliesAgentLoopClassification}
     *      so a scope can stay inside the agent-CHEAP tier band (tier filter ON) yet still consider agent-INELIGIBLE
     *      models. Set FALSE for non-agent-loop scopes whose op is plain structured-JSON output, NOT the interactive
     *      tool-calling agent: MEMORY_MANAGEMENT (extract/reconcile) must NOT depend on agentEligible — a strong cheap
     *      model that is great at memory classification but is held out of the live agent (e.g. a reasoning model with
     *      non-uniform tool-calling across providers) must still win the memory curator. When NOT explicitly set, it
     *      FOLLOWS {@see $appliesAgentLoopClassification} — the pre-decouple semantic every existing caller relied on
     *      (`appliesAgentLoopClassification: false` alone used to skip the agentEligible gate too, e.g. the FAST_CHEAP
     *      reach-the-ineligible-speedster case); only the curator/background scopes pass it explicitly.
     */
    public bool $requiresAgentEligible;

    public function __construct(
        string $preferredTier,
        ?string $preferredVendor = null,
        ?float $maxBlendedCostPer1MUsd = null,
        bool $interactive = false,
        bool $requiresToolCalling = true,
        bool $appliesAgentLoopClassification = true,
        ?bool $requiresAgentEligible = null,
    ) {
        parent::__construct();
        $this->preferredTier = $preferredTier;
        $this->preferredVendor = $preferredVendor;
        $this->maxBlendedCostPer1MUsd = $maxBlendedCostPer1MUsd;
        $this->interactive = $interactive;
        $this->requiresToolCalling = $requiresToolCalling;
        $this->appliesAgentLoopClassification = $appliesAgentLoopClassification;
        // null = not explicitly chosen → inherit the agent-loop classification switch (back-compat: turning the
        // classification off used to turn the agentEligible gate off with it).
        $this->requiresAgentEligible = $requiresAgentEligible ?? $appliesAgentLoopClassification;
    }
}
