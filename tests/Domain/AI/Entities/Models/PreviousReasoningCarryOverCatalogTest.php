<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIModelPreviousReasoningCarryOver;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** The catalog's carry-over of a model's own previous reasoning (`agenticUseCase.previousReasoning`). */
class PreviousReasoningCarryOverCatalogTest extends TestCase
{
    public function testKimiK31ProCarriesTheCurrentTurnsReasoning(): void
    {
        $kimi = (new AIModelsService())->getAIModelByName(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO);

        $this->assertNotNull($kimi);
        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN, $kimi->getAgenticPreviousReasoningCarryOver()?->getCarryOver());
        $this->assertNull($kimi->agenticUseCase?->reasoningEffort);
    }

    public function testLunaAndSolCapTheirReasoningAtOneThousandTokens(): void
    {
        foreach ([AIModel::MODEL_OPENAI_GPT6_LUNA, AIModel::MODEL_OPENAI_GPT6_2_SOL] as $modelName) {
            $aiModel = (new AIModelsService())->getAIModelByName($modelName);
            $previousReasoning = $aiModel?->getAgenticPreviousReasoningCarryOver();

            $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, $previousReasoning?->getCarryOver(), $modelName);
            $this->assertSame(1000, $previousReasoning->capTokens, $modelName);
            $this->assertNull($previousReasoning->keptTokensAtCut, $modelName);
            $this->assertNull($previousReasoning->keptStepsAtCut, $modelName);
            $this->assertNull($previousReasoning->headroomTokensAfterCut, $modelName);
        }
        $this->assertSame('low', (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_LUNA)?->agenticUseCase?->reasoningEffort);
    }

    public function testOpusCarriesEveryEarlierThinkingBlock(): void
    {
        $opus = (new AIModelsService())->getAIModelByName(AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5);

        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, $opus?->getAgenticPreviousReasoningCarryOver()?->getCarryOver(), 'the catalog wire is messages');
        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, $opus->getAgenticPreviousReasoningCarryOver(AIModel::AGENT_WIRE_API_MESSAGES)?->getCarryOver());
        $this->assertNull($opus->getAgenticPreviousReasoningCarryOver(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS), 'measured on messages only');
        $this->assertSame('high', $opus->agenticUseCase->reasoningEffort);
    }

    public function testAModelWithoutTheBlockLeavesItToTheCaller(): void
    {
        $gpt61Sol = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_1_SOL);

        $this->assertNotNull($gpt61Sol);
        $this->assertNull($gpt61Sol->getAgenticPreviousReasoningCarryOver());
    }

    public function testInvalidValuesReadAsUnset(): void
    {
        $this->assertNull(AIModelPreviousReasoningCarryOver::fromCatalogArray(['carryOver' => 'sometimes', 'capTokens' => 0, 'keptStepsAtCut' => 0]));

        $previousReasoning = AIModelPreviousReasoningCarryOver::fromCatalogArray([
            'carryOver' => 'sometimes',
            'capTokens' => 8000,
            'keptTokensAtCut' => 2000,
            'keptStepsAtCut' => 2,
            'headroomTokensAfterCut' => -1,
        ]);
        $this->assertNotNull($previousReasoning);
        $this->assertNull($previousReasoning->getCarryOver());
        $this->assertSame(8000, $previousReasoning->capTokens);
        $this->assertSame(2000, $previousReasoning->keptTokensAtCut);
        $this->assertSame(2, $previousReasoning->keptStepsAtCut);
        $this->assertNull($previousReasoning->headroomTokensAfterCut);
    }

    public function testTheRemovedFlatCatalogKeyIsNotRead(): void
    {
        $this->assertFalse(property_exists(AIModel::class, 'previousReasoningCarryOver'));
    }
}
