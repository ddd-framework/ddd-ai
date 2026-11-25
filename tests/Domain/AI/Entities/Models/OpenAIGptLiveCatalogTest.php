<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** The GPT-Live realtime voice models: AUDIO, priced by session second, found by their wire id, never a tier candidate. */
class OpenAIGptLiveCatalogTest extends TestCase
{
    public function testGptLive11IsAnAudioModelPricedBySessionSecond(): void
    {
        $gptLive11 = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT_LIVE_1_1);

        $this->assertNotNull($gptLive11);
        $this->assertSame('gpt-live-1.1', $gptLive11->externalId);
        $this->assertSame(AIModel::TYPE_AUDIO, $gptLive11->type);
        $this->assertEqualsWithDelta(0.05, $gptLive11->settings->getEstimatedCostsForSessionSeconds(60)->amount, 0.000001);
    }

    public function testGptLive1KeepsItsPriceForEarlierSessions(): void
    {
        $gptLive1 = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT_LIVE_1);

        $this->assertNotNull($gptLive1);
        $this->assertSame(AIModel::TYPE_AUDIO, $gptLive1->type);
        $this->assertEqualsWithDelta(0.05, $gptLive1->settings->getEstimatedCostsForSessionSeconds(60)->amount, 0.000001);
    }

    public function testAModelIsFoundByItsWireId(): void
    {
        $aiModelsService = new AIModelsService();

        $this->assertSame(AIModel::MODEL_OPENAI_GPT_LIVE_1_1, $aiModelsService->getAIModelByExternalId('gpt-live-1.1')?->name);
        $this->assertSame(AIModel::MODEL_OPENAI_GPT_LIVE_1, $aiModelsService->getAIModelByExternalId(' gpt-live-1 ', AIModel::TYPE_AUDIO)?->name);
        $this->assertNull($aiModelsService->getAIModelByExternalId('gpt-live-1.1', AIModel::TYPE_LANGUAGE));
        $this->assertNull($aiModelsService->getAIModelByExternalId('no-such-model'));
        $this->assertNull($aiModelsService->getAIModelByExternalId(''));
    }

    public function testNoLiveModelIsAModelSelectionCandidate(): void
    {
        $catalog = require dirname(__DIR__, 5) . '/config/app/AI/models.php';

        foreach ([AIModel::MODEL_OPENAI_GPT_LIVE_1_1, AIModel::MODEL_OPENAI_GPT_LIVE_1] as $modelName) {
            $this->assertNotSame(AIModel::TYPE_LANGUAGE, $catalog[$modelName]['type']);
            $this->assertArrayNotHasKey('agentTier', $catalog[$modelName]);
        }
    }
}
