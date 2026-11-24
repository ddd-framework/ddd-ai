<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use PHPUnit\Framework\TestCase;

class AnthropicClaude5CatalogTest extends TestCase
{
    protected array $catalog;

    protected function setUp(): void
    {
        $this->catalog = dddAiModelCatalog();
    }

    /** @return float The price per 1M tokens, from the per-1000 figure the catalog stores */
    protected function perMillion(float $perThousandTokens): float
    {
        return round($perThousandTokens * 1000, 6);
    }

    public function testTheThreeClaude5ModelsAreInTheCatalogWithTheirPublishedPrices(): void
    {
        $expected = [
            // model constant => [input $/1M, output $/1M, cached read $/1M, wire id, openrouter id, effort]
            AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5 => [4.0, 20.0, 0.2, 'claude-opus-5-5', 'anthropic/claude-opus-5.5', 'high'],
            AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5 => [2.0, 10.0, 0.2, 'claude-sonnet-5', 'anthropic/claude-sonnet-5', 'medium'],
            AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1 => [10.0, 50.0, 0.25, 'claude-fable-5-1', 'anthropic/claude-fable-5.1', 'high'],
        ];
        foreach ($expected as $modelName => [$input, $output, $cached, $wireId, $openRouterId, $effort]) {
            $row = $this->catalog[$modelName] ?? null;
            $this->assertIsArray($row, "$modelName is missing from the catalog");
            $this->assertSame($input, $this->perMillion($row['settings']['costsPer1000InputTokensInUSD']), "$modelName input");
            $this->assertSame($output, $this->perMillion($row['settings']['costsPer1000OuputTokensInUSD']), "$modelName output");
            $this->assertSame($cached, $this->perMillion($row['settings']['costsPer1000CachedInputTokensInUSD']), "$modelName cached");
            $this->assertSame($wireId, $row['externalId'], "$modelName wire id");
            $this->assertSame($openRouterId, $row['openRouterExternalId'], "$modelName OpenRouter id");
            $this->assertSame($effort, $row['agenticUseCase']['reasoningEffort'], "$modelName reasoning effort");
            $this->assertSame(AIModel::VENDOR_ANTHROPIC, $row['vendor']);
            $this->assertSame(1000000, $row['settings']['maxInputTokens'], "$modelName context");
            $this->assertSame(128000, $row['settings']['maxOutputTokens'], "$modelName output cap");
            $this->assertTrue($row['isReasoningModel'] && $row['hasVisionCapabilities'], "$modelName modalities");
        }
    }

    public function testTheNewModelsCarryNoInventedMeasurements(): void
    {
        // no speed and no benchmark was measured for these models; an invented number is worse than a missing one
        foreach ([AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1] as $modelName) {
            $this->assertSame([], $this->catalog[$modelName]['speed'], "$modelName speed");
            $this->assertSame([], $this->catalog[$modelName]['benchmarks'], "$modelName benchmarks");
        }
    }

    public function testWithoutABenchmarkTheNewModelsCannotChangeATierPick(): void
    {
        // getAgentEligibleModels() keeps only models whose agenticScore is not null, and the score comes from an
        // AGENTIC_WEIGHTS benchmark — so an agentTier on a benchmark-less row is inert until one is measured.
        foreach ([AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1] as $modelName) {
            $this->assertSame([], $this->catalog[$modelName]['benchmarks']);
            $this->assertSame(AIModel::AGENT_TIER_PREMIUM, $this->catalog[$modelName]['agentTier']);
        }
    }

    public function testTheModelsOfTheFiveFiveLineAndFable52AreInTheCatalogWithTheirPublishedPrices(): void
    {
        $expected = [
            // model constant => [input $/1M, output $/1M, cached read $/1M, wire id, openrouter id, effort, accepts a forced tool choice]
            AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5_5 => [2.0, 10.0, 0.1, 'claude-sonnet-5-5', 'anthropic/claude-sonnet-5.5', 'high', false],
            AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_5_5 => [0.1, 0.5, 0.01, 'claude-haiku-5-5', 'anthropic/claude-haiku-5.5', 'medium', true],
            AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_2 => [8.0, 40.0, 0.2, 'claude-fable-5-2', 'anthropic/claude-fable-5.2', 'high', false],
        ];
        foreach ($expected as $modelName => [$input, $output, $cached, $wireId, $openRouterId, $effort, $acceptsForcedToolChoice]) {
            $row = $this->catalog[$modelName] ?? null;
            $this->assertIsArray($row, "$modelName is missing from the catalog");
            $this->assertSame($input, $this->perMillion($row['settings']['costsPer1000InputTokensInUSD']), "$modelName input");
            $this->assertSame($output, $this->perMillion($row['settings']['costsPer1000OuputTokensInUSD']), "$modelName output");
            $this->assertSame($cached, $this->perMillion($row['settings']['costsPer1000CachedInputTokensInUSD']), "$modelName cached");
            $this->assertSame($wireId, $row['externalId'], "$modelName wire id");
            $this->assertSame($openRouterId, $row['openRouterExternalId'], "$modelName OpenRouter id");
            $this->assertSame($effort, $row['agenticUseCase']['reasoningEffort'], "$modelName reasoning effort");
            $this->assertSame($acceptsForcedToolChoice, $row['supportsForcedToolChoice'], "$modelName forced tool choice");
            $this->assertSame(AIModel::VENDOR_ANTHROPIC, $row['vendor']);
            $this->assertSame(1000000, $row['settings']['maxInputTokens'], "$modelName context");
            $this->assertSame(128000, $row['settings']['maxOutputTokens'], "$modelName output cap");
            $this->assertSame([], $row['speed'], "$modelName speed");
            $this->assertSame([], $row['benchmarks'], "$modelName benchmarks");
            $this->assertSame(AIModel::PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL, $row['settings']['promptCacheMode'], "$modelName cache mode");
        }
        // Haiku 5.5 is billed at five times the rate above 100K prompt tokens
        $haikuSettings = $this->catalog[AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_5_5]['settings'];
        $this->assertSame(100000, $haikuSettings['inputTierThresholdTokens']);
        $this->assertSame(0.5, $this->perMillion($haikuSettings['costsPer1000InputTokensInUSDAboveThreshold']));
        $this->assertSame(2.5, $this->perMillion($haikuSettings['costsPer1000OutputTokensInUSDAboveThreshold']));
    }

    public function testTheModelsProbedOnTheMessagesApiNameItAsTheirAgentWire(): void
    {
        $modelsOnTheMessagesApi = [
            AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5,
            AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5_5,
            AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_5_5,
            AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1,
            AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_2,
        ];
        foreach ($modelsOnTheMessagesApi as $modelName) {
            $this->assertSame(AIModel::AGENT_WIRE_API_MESSAGES, $this->catalog[$modelName]['settings']['agentWireApi'] ?? null, "$modelName agent wire");
        }
        // Sonnet 5 refuses mid-conversation tool additions and Haiku 4.5 has no adaptive thinking: both stay on Chat Completions
        foreach ([AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5] as $modelName) {
            $this->assertArrayNotHasKey('agentWireApi', $this->catalog[$modelName]['settings'], "$modelName agent wire");
        }
        // no other vendor's model names the Messages API
        foreach ($this->catalog as $modelName => $row) {
            if (($row['settings']['agentWireApi'] ?? null) === AIModel::AGENT_WIRE_API_MESSAGES) {
                $this->assertSame(AIModel::VENDOR_ANTHROPIC, $row['vendor'] ?? null, "$modelName vendor");
            }
        }
    }

    public function testTheRetiredModelsAreGone(): void
    {
        $this->assertFalse(defined(AIModel::class . '::MODEL_ANTHROPIC_CLAUDE_OPUS_4_8'), 'the Opus 4.8 constant');
        $this->assertFalse(defined(AIModel::class . '::MODEL_ANTHROPIC_CLAUDE_SONNET_4_6'), 'the Sonnet 4.6 constant');
        $this->assertArrayNotHasKey('ANTHROPIC.CLAUDE_OPUS_4_8', $this->catalog);
        $this->assertArrayNotHasKey('ANTHROPIC.CLAUDE_SONNET_4_6', $this->catalog);
        $this->assertNotContains('ANTHROPIC.CLAUDE_OPUS_4_8', AIModel::getModelNames());
    }

    public function testHaikuIsUntouched(): void
    {
        // Haiku 4.5 is current and stays — 200K context, 64K output is its real envelope, not an oversight
        $haiku = $this->catalog[AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5];
        $this->assertSame(200000, $haiku['settings']['maxInputTokens']);
        $this->assertSame(64000, $haiku['settings']['maxOutputTokens']);
    }

    public function testEveryRetiredNameResolvesToASuccessorThatExists(): void
    {
        $this->assertNotEmpty(AIModel::RETIRED_MODEL_SUCCESSORS);
        foreach (AIModel::RETIRED_MODEL_SUCCESSORS as $retiredName => $successorName) {
            $this->assertArrayNotHasKey($retiredName, $this->catalog, "$retiredName must be gone from the catalog");
            $this->assertArrayHasKey($successorName, $this->catalog, "the successor of $retiredName must exist");
        }
    }

    public function testAStoredRetiredNameStillResolvesAndSaysSo(): void
    {
        $service = new AIModelsService();

        $resolved = $service->getAIModelByName('ANTHROPIC.CLAUDE_OPUS_4_8');
        $this->assertNotNull($resolved, 'a stored row must stay readable after the model is retired');
        $this->assertSame(AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, $resolved->name);
        $this->assertSame('ANTHROPIC.CLAUDE_OPUS_4_8', $resolved->resolvedFromRetiredModelName,
            'a record of what ran must not claim the successor ran');

        $sonnet = $service->getAIModelByName('ANTHROPIC.CLAUDE_SONNET_4_6');
        $this->assertSame(AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, $sonnet->name);
        $this->assertSame('ANTHROPIC.CLAUDE_SONNET_4_6', $sonnet->resolvedFromRetiredModelName);
    }

    public function testANormalLookupIsNotMarkedAndAnUnknownNameStillReturnsNull(): void
    {
        $service = new AIModelsService();

        $current = $service->getAIModelByName(AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5);
        $this->assertSame(AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, $current->name);
        $this->assertNull($current->resolvedFromRetiredModelName);

        $this->assertNull($service->getAIModelByName('ANTHROPIC.NO_SUCH_MODEL'));
    }

    public function testTheCatalogStaysConsistent(): void
    {
        $declaredNames = AIModel::getModelNames();
        $this->assertSame([], array_values(array_diff(array_keys($this->catalog), $declaredNames)), 'entries without a constant');
        $this->assertSame([], array_values(array_diff($declaredNames, array_keys($this->catalog))), 'constants without an entry');
        $this->assertSame(count($declaredNames), count(array_unique($declaredNames)), 'duplicate model names');
    }
}
