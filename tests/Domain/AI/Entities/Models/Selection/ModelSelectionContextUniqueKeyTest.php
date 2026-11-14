<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\Models\Selection;

use DDD\Domain\AI\Entities\Models\Selection\ModelManagerPolicy;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use PHPUnit\Framework\TestCase;

/**
 * The key of the selection cache covers every policy field the selection reads: two contexts that differ only in the
 * agent-loop classification or in the agent-eligibility requirement must not share a cached pick.
 */
class ModelSelectionContextUniqueKeyTest extends TestCase
{
    public function testContextsThatDifferOnlyInTheAgentLoopClassificationHaveDifferentKeys(): void
    {
        $classifiedContext = new ModelSelectionContext(new ModelManagerPolicy(ModelTier::CHEAP, appliesAgentLoopClassification: true, requiresAgentEligible: true));
        $unclassifiedContext = new ModelSelectionContext(new ModelManagerPolicy(ModelTier::CHEAP, appliesAgentLoopClassification: false, requiresAgentEligible: true));

        $this->assertNotSame($classifiedContext->uniqueKey(), $unclassifiedContext->uniqueKey());
    }

    public function testContextsThatDifferOnlyInTheAgentEligibilityRequirementHaveDifferentKeys(): void
    {
        $eligibleOnlyContext = new ModelSelectionContext(new ModelManagerPolicy(ModelTier::CHEAP, requiresAgentEligible: true));
        $anyModelContext = new ModelSelectionContext(new ModelManagerPolicy(ModelTier::CHEAP, requiresAgentEligible: false));

        $this->assertNotSame($eligibleOnlyContext->uniqueKey(), $anyModelContext->uniqueKey());
    }
}
