<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

/** GPT-6.2 Sol reaches the catalog with its ids, capabilities and prices; it is no agent model. */
class OpenAIGpt62SolCatalogTest extends TestCase
{
    public function testGpt62SolCarriesItsIdsCapabilitiesAndPrices(): void
    {
        $gpt62Sol = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_2_SOL);

        $this->assertNotNull($gpt62Sol);
        $this->assertSame('openai/gpt-6.2-sol', $gpt62Sol->openRouterExternalId);
        $this->assertSame('gpt-6.2-sol', $gpt62Sol->externalId);
        $this->assertTrue($gpt62Sol->isReasoningModel);
        $this->assertTrue($gpt62Sol->hasVisionCapabilities);
        $this->assertSame(128000, $gpt62Sol->settings->maxOutputTokens);
        $this->assertSame(0.002, $gpt62Sol->settings->costsPer1000KInputTokens->amount);
        $this->assertSame(0.01, $gpt62Sol->settings->costsPer1000KOutputTokens->amount);
        $this->assertSame(0.0001, $gpt62Sol->settings->costsPer1000KCachedInputTokens->amount);
        $this->assertSame(272000, $gpt62Sol->settings->inputTierThresholdTokens);
        $this->assertSame(0.004, $gpt62Sol->settings->costsPer1000InputTokensAboveThreshold->amount);
        $this->assertSame(0.015, $gpt62Sol->settings->costsPer1000OutputTokensAboveThreshold->amount);
        $this->assertNull($gpt62Sol->agentTier ?? null);
    }
}
