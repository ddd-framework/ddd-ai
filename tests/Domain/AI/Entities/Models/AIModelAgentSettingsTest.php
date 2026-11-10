<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIImageModelSetting;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Tests\AI\Domain\AI\Entities\Models\Fixtures\CatalogProbeAIModelsService;
use PHPUnit\Framework\TestCase;

/** The wire API and the reasoning context an agent uses for a model come from the model's catalog settings. */
class AIModelAgentSettingsTest extends TestCase
{
    public function testAModelWithoutTheSettingsSpeaksChatCompletionsAndPinsNoReasoningContext(): void
    {
        $aIModel = $this->createLanguageModel(null, null);

        $this->assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $aIModel->getAgentWireApi());
        $this->assertNull($aIModel->getAgentReasoningContext());
    }

    public function testTheCatalogSettingsAreRead(): void
    {
        $aIModel = $this->createLanguageModel(' Responses ', AIModel::REASONING_CONTEXT_ALL_TURNS);

        $this->assertSame(AIModel::AGENT_WIRE_API_RESPONSES, $aIModel->getAgentWireApi());
        $this->assertSame(AIModel::REASONING_CONTEXT_ALL_TURNS, $aIModel->getAgentReasoningContext());
    }

    public function testAnUnknownValueFallsBackToTheDefault(): void
    {
        $aIModel = $this->createLanguageModel('grpc', 'every_turn');

        $this->assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $aIModel->getAgentWireApi());
        $this->assertNull($aIModel->getAgentReasoningContext());
    }

    public function testTheSettingsHydrateFromACatalogEntry(): void
    {
        $catalogSettings = (object)['agentWireApi' => 'responses', 'agentReasoningContext' => 'auto'];
        $aILanguageModelSetting = new AILanguageModelSetting();
        $aILanguageModelSetting->setPropertiesFromObject($catalogSettings);

        $this->assertSame('responses', $aILanguageModelSetting->agentWireApi);
        $this->assertSame('auto', $aILanguageModelSetting->agentReasoningContext);
    }

    public function testTheCatalogHydrationFillsTheAgentSettings(): void
    {
        $catalogProbeAIModelsService = new CatalogProbeAIModelsService();
        $aIModel = $catalogProbeAIModelsService->createAIModelFromCatalogEntry('TEST.AGENT_SETTINGS_PROBE', [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => ['agentWireApi' => 'responses', 'agentReasoningContext' => AIModel::REASONING_CONTEXT_ALL_TURNS],
        ]);
        $aIModelWithoutTheKeys = $catalogProbeAIModelsService->createAIModelFromCatalogEntry('TEST.AGENT_SETTINGS_PLAIN', [
            'type' => AIModel::TYPE_LANGUAGE,
            'settings' => [],
        ]);

        $this->assertSame(AIModel::AGENT_WIRE_API_RESPONSES, $aIModel->getAgentWireApi());
        $this->assertSame(AIModel::REASONING_CONTEXT_ALL_TURNS, $aIModel->getAgentReasoningContext());
        $this->assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $aIModelWithoutTheKeys->getAgentWireApi());
        $this->assertNull($aIModelWithoutTheKeys->getAgentReasoningContext());
    }

    public function testAnImageModelHasNoAgentSettings(): void
    {
        $aIModel = new AIModel();
        $aIModel->name = 'fixture-image-model';
        $aIModel->settings = new AIImageModelSetting();

        $this->assertSame(AIModel::AGENT_WIRE_API_CHAT_COMPLETIONS, $aIModel->getAgentWireApi());
        $this->assertNull($aIModel->getAgentReasoningContext());
    }

    protected function createLanguageModel(?string $agentWireApi, ?string $agentReasoningContext): AIModel
    {
        $aIModel = new AIModel();
        $aIModel->name = 'fixture-language-model';
        $aIModel->settings = new AILanguageModelSetting();
        $aIModel->settings->agentWireApi = $agentWireApi;
        $aIModel->settings->agentReasoningContext = $agentReasoningContext;
        return $aIModel;
    }
}
