<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Entities\Models;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AILanguageModelSetting;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * How a model's prompt cache is addressed and what a cache write costs are catalog knowledge: the egress sends
 * explicit `prompt_cache_breakpoint` markers only for a model that declares the mode, and the cost estimate prices
 * written tokens with the model's write multiple. Both have to survive the catalog hydration.
 */
class PromptCacheCatalogTest extends TestCase
{
    protected function hydrateLanguageModel(array $settings): AIModel
    {
        $aiModelsService = new class extends AIModelsService {
            public function hydrate(array $modelConfig): AIModel
            {
                return $this->createAIModelFromConfig('TEST.PROMPT_CACHE_PROBE', $modelConfig);
            }
        };
        return $aiModelsService->hydrate(['type' => AIModel::TYPE_LANGUAGE, 'settings' => $settings]);
    }

    protected function gpt6Luna(): AIModel
    {
        $gpt6Luna = (new AIModelsService())->getAIModelByName(AIModel::MODEL_OPENAI_GPT6_LUNA);
        $this->assertNotNull($gpt6Luna);
        return $gpt6Luna;
    }

    public function testTheBreakpointModelsCarryModeAndWritePricesThroughTheHydration(): void
    {
        foreach ([AIModel::MODEL_OPENAI_GPT5_6_LUNA, AIModel::MODEL_OPENAI_GPT6_LUNA, AIModel::MODEL_OPENAI_GPT6_SOL, AIModel::MODEL_OPENAI_GPT6_1_SOL] as $modelName) {
            $aiModel = (new AIModelsService())->getAIModelByName($modelName);

            $this->assertNotNull($aiModel, $modelName);
            $this->assertSame(AIModel::PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS, $aiModel->getPromptCacheMode(), $modelName);
            $this->assertTrue($aiModel->usesExplicitPromptCacheBreakpoints(), $modelName);
            $this->assertSame(1.25, $aiModel->getPromptCacheWritePriceMultiplier(), $modelName);
            $this->assertSame(2.5, $aiModel->getPromptCacheWritePriceMultiplier(AIModel::PROMPT_CACHE_WRITE_TTL_TWELVE_HOURS), $modelName);
        }
    }

    public function testAModelWithoutTheKeysHasNoModeAndNoWritePremium(): void
    {
        $aiModel = $this->hydrateLanguageModel([
            'costsPer1000InputTokensInUSD' => 0.002,
            'costsPer1000OuputTokensInUSD' => 0.008,
        ]);

        $this->assertSame(AIModel::PROMPT_CACHE_MODE_NONE, $aiModel->getPromptCacheMode());
        $this->assertFalse($aiModel->usesExplicitPromptCacheBreakpoints());
        $this->assertNull($aiModel->getPromptCacheWritePriceMultiplier());
        $this->assertEqualsWithDelta(
            $aiModel->calculateEstimatedCostAmount(1000, 100),
            $aiModel->calculateEstimatedCostAmount(1000, 100, null, 0, 0, 1000),
            1e-12
        );
    }

    public function testWrittenTokensCostTheInputRateTimesTheWriteMultiple(): void
    {
        $gpt6Luna = $this->gpt6Luna();
        /** @var AILanguageModelSetting $settings */
        $settings = $gpt6Luna->settings;
        $inputRate = $settings->costsPer1000KInputTokens->amount;
        $baseline = $gpt6Luna->calculateEstimatedCostAmount(1000, 0);

        $this->assertEqualsWithDelta(
            0.6 * $inputRate + 0.4 * $inputRate * 1.25,
            $gpt6Luna->calculateEstimatedCostAmount(1000, 0, null, 0, 0, 400),
            1e-12
        );
        $this->assertEqualsWithDelta(
            0.6 * $inputRate + 0.4 * $inputRate * 2.5,
            $gpt6Luna->calculateEstimatedCostAmount(1000, 0, null, 0, 0, 400, AIModel::PROMPT_CACHE_WRITE_TTL_TWELVE_HOURS),
            1e-12
        );
        $this->assertEqualsWithDelta($inputRate, $baseline, 1e-12);
    }

    /** Written tokens are a subset of the uncached ones: a larger count is capped, cached tokens keep their rate. */
    public function testWrittenTokensAreCappedAtTheUncachedTokens(): void
    {
        $gpt6Luna = $this->gpt6Luna();
        /** @var AILanguageModelSetting $settings */
        $settings = $gpt6Luna->settings;
        $inputRate = $settings->costsPer1000KInputTokens->amount;
        $cachedRate = $settings->costsPer1000KCachedInputTokens->amount;

        $this->assertEqualsWithDelta(
            0.3 * $inputRate * 1.25 + 0.7 * $cachedRate,
            $gpt6Luna->calculateEstimatedCostAmount(1000, 0, null, 700, 0, 5000),
            1e-12
        );
    }

    public function testTheTieredBranchAppliesTheMultipleToTheAboveThresholdRate(): void
    {
        $aiModel = $this->hydrateLanguageModel([
            'costsPer1000InputTokensInUSD' => 0.001,
            'costsPer1000OuputTokensInUSD' => 0.004,
            'inputTierThresholdTokens' => 1000,
            'costsPer1000InputTokensInUSDAboveThreshold' => 0.003,
            'costsPer1000OutputTokensInUSDAboveThreshold' => 0.006,
            'promptCacheMode' => AIModel::PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS,
            'promptCacheWritePriceMultipliers' => [AIModel::PROMPT_CACHE_WRITE_TTL_DEFAULT => 1.25],
        ]);

        $this->assertEqualsWithDelta(
            1.0 * 0.003 + 1.0 * 0.003 * 1.25,
            $aiModel->calculateEstimatedCostAmount(2000, 0, null, 0, 0, 1000),
            1e-12
        );
    }

    /** An integer multiple in a config row becomes the float the estimate multiplies with. */
    public function testAnIntegerMultipleInTheConfigIsHydratedAsAFloat(): void
    {
        $aiModel = $this->hydrateLanguageModel([
            'promptCacheWritePriceMultipliers' => [AIModel::PROMPT_CACHE_WRITE_TTL_TWELVE_HOURS => 2],
        ]);

        $this->assertSame(2.0, $aiModel->getPromptCacheWritePriceMultiplier(AIModel::PROMPT_CACHE_WRITE_TTL_TWELVE_HOURS));
        $this->assertNull($aiModel->getPromptCacheWritePriceMultiplier());
    }

    public function testThePromptCacheModeIsConstrainedToTheFourModes(): void
    {
        $choiceAttributes = (new ReflectionProperty(AILanguageModelSetting::class, 'promptCacheMode'))
            ->getAttributes(Choice::class);

        $this->assertCount(1, $choiceAttributes);
        $this->assertSame(
            [
                AIModel::PROMPT_CACHE_MODE_EXPLICIT_BREAKPOINTS,
                AIModel::PROMPT_CACHE_MODE_AUTOMATIC_PREFIX,
                AIModel::PROMPT_CACHE_MODE_ANTHROPIC_CACHE_CONTROL,
                AIModel::PROMPT_CACHE_MODE_NONE,
            ],
            $choiceAttributes[0]->getArguments()['choices']
        );
    }
}
