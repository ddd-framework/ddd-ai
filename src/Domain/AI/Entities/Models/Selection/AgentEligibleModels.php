<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\Selection;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The set of AIModels the agent may run on — an ObjectSet of {@see AgentEligibleModel}, best-first (highest
 * agentic score). Built by {@see \DDD\Domain\AI\Services\AIModelsService::getAgentEligibleModels()} and
 * returned by the admin model-picker endpoint so the console offers ONLY agent-suitable models, each labelled with
 * its tier + score.
 *
 * @property AgentEligibleModel[] $elements
 * @method AgentEligibleModel[] getElements()
 * @method AgentEligibleModel|null first()
 */
class AgentEligibleModels extends ObjectSet
{
}
