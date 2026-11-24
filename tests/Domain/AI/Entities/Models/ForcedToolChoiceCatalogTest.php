<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** The catalog's forced tool choice: refused by the Claude models with always-on thinking, explicit for every tool model. */
class ForcedToolChoiceCatalogTest extends TestCase
{
    public function testClaudeOpus55AndFable51RefuseAForcedToolChoice(): void
    {
        $aiModelsService = new AIModelsService();
        foreach ([AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1] as $modelName) {
            $aiModel = $aiModelsService->getAIModelByName($modelName);
            $this->assertNotNull($aiModel, $modelName);
            $this->assertFalse($aiModel->acceptsForcedToolChoice(), $modelName);
        }
    }

    public function testEveryModelWithAParallelToolCallsFlagAlsoDecidesTheForcedToolChoice(): void
    {
        foreach ((new AIModelsService())->getAIModels()->getElements() as $aiModel) {
            if ($aiModel->supportsParallelToolCalls === null) {
                continue;
            }
            $this->assertNotNull($aiModel->supportsForcedToolChoice, (string)$aiModel->name);
        }
        $sonnet5 = (new AIModelsService())->getAIModelByName(AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5);
        $this->assertTrue($sonnet5?->acceptsForcedToolChoice());
    }

    public function testAnUndecidedModelForcesAsBefore(): void
    {
        $this->assertTrue((new AIModel())->acceptsForcedToolChoice());
    }
}
