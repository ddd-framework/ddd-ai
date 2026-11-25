<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIModelPreviousReasoningCarryOver;
use DDD\Tests\AI\Domain\AI\Entities\Models\Fixtures\CatalogProbeAIModelsService;
use PHPUnit\Framework\TestCase;

/** The carry-over of a model's own previous reasoning per wire API (`agenticUseCase.previousReasoningByWireApi`). */
class PreviousReasoningCarryOverByWireApiTest extends TestCase
{
    public function testTheValueOfTheCallsWireWins(): void
    {
        $aiModel = $this->createModelWithWireValues();

        $previousReasoning = $aiModel->getAgenticPreviousReasoningCarryOver(AIModel::AGENT_WIRE_API_RESPONSES);
        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, $previousReasoning?->getCarryOver());
        $this->assertSame(1000, $previousReasoning->capTokens);
        $this->assertNull($previousReasoning->keptStepsAtCut, 'the wire value replaces the model-wide one as a whole');
        $this->assertSame(1000, $aiModel->getAgenticPreviousReasoningCarryOver(' RESPONSES ')?->capTokens, 'a known wire in any case');
    }

    public function testOtherWiresFallBackToTheModelWideValue(): void
    {
        $aiModel = $this->createModelWithWireValues();

        foreach ([AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, AIModel::AGENT_WIRE_API_MESSAGES, 'grpc'] as $agentWireApi) {
            $previousReasoning = $aiModel->getAgenticPreviousReasoningCarryOver($agentWireApi);
            $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, $previousReasoning?->getCarryOver(), $agentWireApi);
            $this->assertSame(3, $previousReasoning->keptStepsAtCut, $agentWireApi);
        }
    }

    public function testWithoutAnArgumentTheCatalogWireDecides(): void
    {
        $catalogProbeAIModelsService = new CatalogProbeAIModelsService();
        $onResponses = $catalogProbeAIModelsService->createAIModelFromCatalogEntry('TEST.WIRE_RESPONSES', $this->createCatalogEntry(AIModel::AGENT_WIRE_API_RESPONSES));
        $onChatCompletions = $catalogProbeAIModelsService->createAIModelFromCatalogEntry('TEST.WIRE_DEFAULT', $this->createCatalogEntry(null));

        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, $onResponses->getAgenticPreviousReasoningCarryOver()?->getCarryOver());
        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, $onChatCompletions->getAgenticPreviousReasoningCarryOver()?->getCarryOver());
    }

    public function testWithoutAPerWireKeyNothingChanges(): void
    {
        $aiModel = (new CatalogProbeAIModelsService())->createAIModelFromCatalogEntry('TEST.NO_WIRE_KEY', [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => [],
            'agenticUseCase' => ['previousReasoning' => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN]],
        ]);

        $this->assertNull($aiModel->agenticUseCase->previousReasoningByWireApi);
        foreach ([null, ...AIModel::CATALOG_AGENT_WIRE_APIS] as $agentWireApi) {
            $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN, $aiModel->getAgenticPreviousReasoningCarryOver($agentWireApi)?->getCarryOver());
        }
    }

    public function testAWireValueWithoutAModelWideValueAppliesOnItsWireOnly(): void
    {
        $aiModel = (new CatalogProbeAIModelsService())->createAIModelFromCatalogEntry('TEST.WIRE_ONLY', [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => [],
            'agenticUseCase' => ['previousReasoningByWireApi' => [AIModel::AGENT_WIRE_API_MESSAGES => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL]]],
        ]);

        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, $aiModel->getAgenticPreviousReasoningCarryOver(AIModel::AGENT_WIRE_API_MESSAGES)?->getCarryOver());
        $this->assertNull($aiModel->getAgenticPreviousReasoningCarryOver(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS));
        $this->assertNull($aiModel->getAgenticPreviousReasoningCarryOver(), 'the catalog wire is Chat Completions');
    }

    public function testUnknownKeysAndInvalidValuesDoNotBreakTheCatalog(): void
    {
        $aiModel = (new CatalogProbeAIModelsService())->createAIModelFromCatalogEntry('TEST.UNKNOWN_WIRE', [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => [],
            'agenticUseCase' => [
                'previousReasoning' => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_OFF],
                'previousReasoningByWireApi' => [
                    'grpc' => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN],
                    0 => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL],
                    '  ' => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL],
                    AIModel::AGENT_WIRE_API_RESPONSES => 'all',
                    AIModel::AGENT_WIRE_API_MESSAGES => ['carryOver' => 'sometimes'],
                ],
            ],
        ]);

        $this->assertSame(1, $aiModel->agenticUseCase->previousReasoningByWireApi?->count(), 'only the unknown wire with a valid value is kept');
        $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_CURRENT_TURN, $aiModel->getAgenticPreviousReasoningCarryOver('grpc')?->getCarryOver());
        foreach (AIModel::CATALOG_AGENT_WIRE_APIS as $agentWireApi) {
            $this->assertSame(AIModelPreviousReasoningCarryOver::CARRY_OVER_OFF, $aiModel->getAgenticPreviousReasoningCarryOver($agentWireApi)?->getCarryOver(), $agentWireApi);
        }
    }

    /** A model with a model-wide `all` (3 steps kept) and `capped` at 1,000 on the Responses API */
    protected function createModelWithWireValues(): AIModel
    {
        return (new CatalogProbeAIModelsService())->createAIModelFromCatalogEntry('TEST.WIRE_VALUES', $this->createCatalogEntry(null));
    }

    /** @return array<string, mixed> */
    protected function createCatalogEntry(?string $agentWireApi): array
    {
        return [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => $agentWireApi !== null ? ['agentWireApi' => $agentWireApi] : [],
            'agenticUseCase' => [
                'previousReasoning' => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_ALL, 'keptStepsAtCut' => 3],
                'previousReasoningByWireApi' => [
                    AIModel::AGENT_WIRE_API_RESPONSES => ['carryOver' => AIModelPreviousReasoningCarryOver::CARRY_OVER_CAPPED, 'capTokens' => 1000],
                ],
            ],
        ];
    }
}
