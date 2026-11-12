<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/**
 * The fast (priority) service tier is per-model catalog knowledge: the egress sends `service_tier: "priority"` only
 * when the SELECTED model says the tier exists, and prices an estimated cost with the model's own factor. Both
 * therefore have to survive the catalog hydration, and a model nobody verified must keep the safe default.
 */
class FastModeCatalogTest extends TestCase
{
    public function testGpt6LunaCarriesTheVerifiedFastTierThroughTheHydration(): void
    {
        $gpt6Luna = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_LUNA);

        $this->assertNotNull($gpt6Luna);
        $this->assertTrue($gpt6Luna->supportsFastMode);
        $this->assertSame(2.0, $gpt6Luna->fastModePriceMultiplier);
    }

    /**
     * Unverified models keep the default — no tier field is sent and no price factor applies. A sibling of the same
     * family is the case that matters: flagging by analogy would send a tier the provider may reject or not bill.
     */
    public function testAModelWithoutTheKeysKeepsTheSafeDefault(): void
    {
        foreach ([AIModel::MODEL_OPENAI_GPT6_SOL, AIModel::MODEL_OPENAI_GPT6_1_SOL] as $modelName) {
            $aiModel = (new AIModelsService())->getAIModelByName($modelName);

            $this->assertNotNull($aiModel, $modelName);
            $this->assertFalse($aiModel->supportsFastMode, $modelName);
            $this->assertNull($aiModel->fastModePriceMultiplier, $modelName);
        }
    }

    /** The hydration casts: an integer factor in a config row becomes the float the cost estimate multiplies with. */
    public function testAnIntegerFactorInTheConfigIsHydratedAsAFloat(): void
    {
        $aiModelsService = new class extends AIModelsService {
            public function hydrate(array $modelConfig): AIModel
            {
                return $this->createAIModelFromConfig('TEST.FAST_MODE_PROBE', $modelConfig);
            }
        };

        $aiModel = $aiModelsService->hydrate(['supportsFastMode' => 1, 'fastModePriceMultiplier' => 2]);

        $this->assertTrue($aiModel->supportsFastMode);
        $this->assertSame(2.0, $aiModel->fastModePriceMultiplier);
    }
}
