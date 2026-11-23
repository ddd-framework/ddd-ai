<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use PHPUnit\Framework\TestCase;

/**
 * An optional request parameter the catalog lists as unsupported for a model is left out of its request: with
 * OpenRouter's require_parameters it would leave no endpoint (Kimi K3.1 Pro: no provider lists parallel_tool_calls).
 */
class ArgusAILanguageModelTraitOptionalParametersTest extends TestCase
{
    protected const array PROBE_TOOL = ['type' => 'function', 'function' => ['name' => 'probeTool', 'parameters' => ['type' => 'object']]];

    public function testTheCatalogDecidesParallelToolCallsExplicitly(): void
    {
        $aiModelsService = new AIModelsService();
        self::assertFalse($aiModelsService->getAIModelByName(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO)->supportsParallelToolCalls);
        foreach ([AIModel::MODEL_OPENAI_GPT6_LUNA, AIModel::MODEL_OPENAI_GPT6_SOL, AIModel::MODEL_OPENAI_GPT6_1_SOL, AIModel::MODEL_OPENAI_GPT6_2_SOL,
            AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5, AIModel::MODEL_ANTHROPIC_CLAUDE_SONNET_5, AIModel::MODEL_ANTHROPIC_CLAUDE_FABLE_5_1,
            AIModel::MODEL_ANTHROPIC_CLAUDE_HAIKU_4_5] as $modelName) {
            self::assertTrue($aiModelsService->getAIModelByName($modelName)->supportsParallelToolCalls, $modelName);
        }
        self::assertNull($aiModelsService->getAIModelByName(AIModel::MODEL_PERPLEXITY_SONAR)->supportsParallelToolCalls, 'no tools: not decided');
        self::assertTrue($aiModelsService->getAIModelByName(AIModel::MODEL_PERPLEXITY_SONAR)->sendsParallelToolCalls(), 'undecided = as before');
    }

    public function testKimiGetsToolsWithoutParallelToolCalls(): void
    {
        $body = $this->buildBodyWithOneTool(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO);
        self::assertArrayHasKey('tools', $body);
        self::assertArrayNotHasKey('parallel_tool_calls', $body);
    }

    public function testAModelWithoutTheRestrictionKeepsParallelToolCalls(): void
    {
        $body = $this->buildBodyWithOneTool(AIModel::MODEL_OPENAI_GPT5_4_MINI);
        self::assertTrue($body['parallel_tool_calls']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildBodyWithOneTool(string $modelName): array
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(defaultAIModelName: $modelName));
        $argusLanguageModelProbe->probeChatCompletionTools = [self::PROBE_TOOL];
        return $argusLanguageModelProbe->buildLoadPayload()['body'];
    }
}
