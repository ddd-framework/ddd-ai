<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Symfony\Commands\AI\Fixtures;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\AIModels;
use DDD\Domain\AI\Entities\Models\Benchmarks\AIModelBenchmarks;
use DDD\Domain\AI\Entities\Models\Speed\AIModelSpeedMeasurements;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * An AIModelsService over a catalog the test supplies instead of the configured one. {@see self::createWithSmallCatalog()}
 * gives five language models whose picks are known:
 *
 * - CHEAP: TEST.CHEAP_ALPHA (OPENAI, score 70, cheapest, 60 tok/s) and TEST.CHEAP_BETA (GOOGLE, score 60, 120 tok/s);
 *   MINIMAX.M3 is CHEAP too but not agent-eligible (the pinned default of the ORCHESTRATOR scope)
 * - STANDARD: TEST.STANDARD_ONE (ANTHROPIC, score 80)
 * - PREMIUM: TEST.PREMIUM_ONE (OPENAI, score 90)
 */
class FixedCatalogAIModelsService extends AIModelsService
{
    public const string CHEAP_ALPHA = 'TEST.CHEAP_ALPHA';

    public const string CHEAP_BETA = 'TEST.CHEAP_BETA';

    public const string STANDARD_ONE = 'TEST.STANDARD_ONE';

    public const string PREMIUM_ONE = 'TEST.PREMIUM_ONE';

    /** @var string The OpenRouter id of {@see self::CHEAP_ALPHA} */
    public const string CHEAP_ALPHA_OPENROUTER_ID = 'test-author/cheap-alpha';

    /** @var array<string, array<string, mixed>> Catalog entries by model name, in the shape of the configured catalog */
    public array $catalogEntries = [];

    /**
     * @return self The service over the small catalog described on the class
     */
    public static function createWithSmallCatalog(): self
    {
        $fixedCatalogAIModelsService = new self();
        $fixedCatalogAIModelsService->catalogEntries = [
            self::CHEAP_ALPHA => self::createLanguageModelEntry(AIModel::VENDOR_OPENAI, AIModel::AGENT_TIER_CHEAP, 70.0, 0.0001, 0.0004, 60.0, self::CHEAP_ALPHA_OPENROUTER_ID),
            self::CHEAP_BETA => self::createLanguageModelEntry(AIModel::VENDOR_GOOGLE, AIModel::AGENT_TIER_CHEAP, 60.0, 0.0002, 0.0008, 120.0, 'test-author/cheap-beta'),
            self::STANDARD_ONE => self::createLanguageModelEntry(AIModel::VENDOR_ANTHROPIC, AIModel::AGENT_TIER_STANDARD, 80.0, 0.001, 0.004, 80.0, null),
            self::PREMIUM_ONE => self::createLanguageModelEntry(AIModel::VENDOR_OPENAI, AIModel::AGENT_TIER_PREMIUM, 90.0, 0.005, 0.02, 50.0, null),
            AIModel::MODEL_MINIMAX_M3 => ['agentEligible' => false, 'speed' => []]
                + self::createLanguageModelEntry(AIModel::VENDOR_MINIMAX, AIModel::AGENT_TIER_CHEAP, 75.0, 0.0003, 0.0012, 0.0, 'minimax/minimax-m3'),
        ];
        return $fixedCatalogAIModelsService;
    }

    /**
     * One language-model catalog entry.
     *
     * @return array<string, mixed>
     */
    public static function createLanguageModelEntry(
        string $vendor,
        string $agentTier,
        float $bfclScore,
        float $costsPer1000InputTokensInUSD,
        float $costsPer1000OutputTokensInUSD,
        float $tokensPerSecond,
        ?string $openRouterExternalId,
    ): array {
        return [
            'type' => AIModel::TYPE_LANGUAGE,
            'vendor' => $vendor,
            'agentTier' => $agentTier,
            'externalId' => strtolower($vendor) . '-test-model',
            'openRouterExternalId' => $openRouterExternalId,
            'benchmarks' => [
                ['benchmark' => AIModelBenchmarks::BENCHMARK_BFCL, 'score' => $bfclScore],
            ],
            'speed' => [
                ['source' => AIModelSpeedMeasurements::SOURCE_OPENROUTER, 'tokensPerSecond' => $tokensPerSecond, 'timeToFirstTokenMs' => 500],
            ],
            'settings' => [
                'maxTokens' => 128000,
                'maxInputTokens' => 128000,
                'maxOutputTokens' => 16000,
                'costsPer1000InputTokensInUSD' => $costsPer1000InputTokensInUSD,
                'costsPer1000OuputTokensInUSD' => $costsPer1000OutputTokensInUSD,
            ],
        ];
    }

    /**
     * @return AIModels The models of {@see self::$catalogEntries}
     */
    public function getAIModels(): AIModels
    {
        $aIModels = new AIModels();
        foreach ($this->catalogEntries as $modelName => $catalogEntry) {
            $aIModel = $this->createAIModelFromConfig($modelName, $catalogEntry);
            $aIModels->add($aIModel);
        }
        return $aIModels;
    }

    /**
     * @param string $aiModelName
     * @return AIModel|null The model of that name in {@see self::$catalogEntries}
     * @throws NotFoundException when the name is unknown and throwErrors is on
     */
    public function getAIModelByName(string $aiModelName): ?AIModel
    {
        $catalogEntry = $this->catalogEntries[$aiModelName] ?? null;
        if ($catalogEntry === null) {
            if ($this->throwErrors) {
                throw new NotFoundException('AiModel not found');
            }
            return null;
        }
        return $this->createAIModelFromConfig($aiModelName, $catalogEntry);
    }
}
