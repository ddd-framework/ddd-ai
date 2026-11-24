<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** The catalog's carry-over of a model's own previous reasoning: explicit for Kimi K3.1 Pro, unset elsewhere. */
class PreviousReasoningCarryOverCatalogTest extends TestCase
{
    public function testKimiK31ProCarriesTheCurrentTurnsReasoning(): void
    {
        $kimi = (new AIModelsService())->getAIModelByName(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO);

        $this->assertNotNull($kimi);
        $this->assertSame(AIModel::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN, $kimi->getPreviousReasoningCarryOver());
    }

    public function testAModelWithoutTheSettingLeavesItToTheCaller(): void
    {
        $gpt62Sol = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_2_SOL);

        $this->assertNotNull($gpt62Sol);
        $this->assertNull($gpt62Sol->getPreviousReasoningCarryOver());
    }

    public function testAnUnknownValueReadsAsUnset(): void
    {
        $aiModel = new AIModel();
        $aiModel->previousReasoningCarryOver = 'sometimes';

        $this->assertNull($aiModel->getPreviousReasoningCarryOver());
    }
}
