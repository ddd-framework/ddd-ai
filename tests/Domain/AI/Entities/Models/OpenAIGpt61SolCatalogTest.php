<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** GPT-6.1 Sol reaches the catalog with its ids, capabilities and prices; it is no agent model. */
class OpenAIGpt61SolCatalogTest extends TestCase
{
    public function testGpt61SolCarriesItsIdsCapabilitiesAndPrices(): void
    {
        $gpt61Sol = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_1_SOL);

        $this->assertNotNull($gpt61Sol);
        $this->assertSame('openai/gpt-6.1-sol', $gpt61Sol->openRouterExternalId);
        $this->assertSame('gpt-6.1-sol', $gpt61Sol->externalId);
        $this->assertTrue($gpt61Sol->isReasoningModel);
        $this->assertTrue($gpt61Sol->hasVisionCapabilities);
        $this->assertSame(128000, $gpt61Sol->settings->maxOutputTokens);
        $this->assertSame(0.002, $gpt61Sol->settings->costsPer1000KInputTokens->amount);
        $this->assertSame(0.01, $gpt61Sol->settings->costsPer1000KOutputTokens->amount);
        $this->assertSame(0.0001, $gpt61Sol->settings->costsPer1000KCachedInputTokens->amount);
        $this->assertSame(272000, $gpt61Sol->settings->inputTierThresholdTokens);
        $this->assertSame(0.004, $gpt61Sol->settings->costsPer1000InputTokensAboveThreshold->amount);
        $this->assertSame(0.015, $gpt61Sol->settings->costsPer1000OutputTokensAboveThreshold->amount);
        $this->assertNull($gpt61Sol->agentTier ?? null);
    }
}
