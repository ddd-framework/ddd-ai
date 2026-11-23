<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Models\Settings\AIModelProviderFilters;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIApiMode;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use PHPUnit\Framework\TestCase;

/** The catalog's OpenRouter provider routing reaches every OpenRouter request body of the model */
class ArgusAILanguageModelTraitProviderFiltersTest extends TestCase
{
    /** @var array<string, mixed> Kimi K3.1 Pro's routing as the catalog declares it */
    protected const array KIMI_PROVIDER_BLOCK = [
        'order' => ['deepinfra', 'fireworks', 'together', 'amazon-bedrock'],
        'ignore' => ['moonshotai', 'alibaba'],
        'allow_fallbacks' => false,
        'only' => ['deepinfra', 'fireworks', 'together', 'amazon-bedrock'],
        'data_collection' => 'deny',
        'require_parameters' => true,
        'quantizations' => ['fp16', 'bf16', 'fp8'],
    ];

    public function testEveryDirectiveIsWrittenOnlyWhenSet(): void
    {
        self::assertSame(['sort' => 'price'], (new AIModelProviderFilters())->applyToProviderBlock(['sort' => 'price']));
        self::assertFalse((new AIModelProviderFilters())->hasAnyDirective());

        $aiModelProviderFilters = new AIModelProviderFilters(
            only: ['deepinfra'],
            dataCollection: AIModelProviderFilters::DATA_COLLECTION_DENY,
            requireParameters: true,
            quantizations: ['bf16'],
        );
        self::assertTrue($aiModelProviderFilters->hasAnyDirective());
        self::assertSame(
            ['only' => ['deepinfra'], 'data_collection' => 'deny', 'require_parameters' => true, 'quantizations' => ['bf16']],
            $aiModelProviderFilters->applyToProviderBlock([])
        );
    }

    public function testTheKimiCatalogEntryCarriesItsRouting(): void
    {
        $kimi = (new AIModelsService())->getAIModelByName(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO);

        self::assertNotNull($kimi);
        self::assertSame(AIModel::VENDOR_MOONSHOT, $kimi->vendor);
        self::assertSame('moonshotai/kimi-k3.1-pro', $kimi->openRouterExternalId);
        self::assertTrue($kimi->hasVisionCapabilities);
        self::assertSame(AIModel::PROMPT_CACHE_MODE_AUTOMATIC_PREFIX, $kimi->getPromptCacheMode());
        self::assertSame(self::KIMI_PROVIDER_BLOCK, $kimi->providerFilters->applyToProviderBlock([]));
    }

    public function testTheChatCompletionsBodyTowardsOpenRouterCarriesTheCatalogRouting(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO,
        ));

        $providerBlock = $argusLanguageModelProbe->buildLoadPayload()['body']['provider'];

        foreach (self::KIMI_PROVIDER_BLOCK as $key => $value) {
            self::assertSame($value, $providerBlock[$key], $key);
        }
    }

    public function testTheResponsesBodyTowardsOpenRouterCarriesTheRoutingAndADirectOneDoesNot(): void
    {
        $argusLanguageModel = new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            apiMode: ArgusOpenAIApiMode::RESPONSES,
            preferredProviders: ['openai'],
        );
        $openRouterProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel($argusLanguageModel)
            ->useLoadEndpoint('POST:/ai/openRouter/responses');
        $directProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(clone $argusLanguageModel)
            ->useLoadEndpoint('POST:/ai/openAi/responses');

        $openRouterBody = $openRouterProbe->buildLoadPayload()['body'];
        self::assertArrayHasKey('input', $openRouterBody);
        self::assertSame(['order' => ['openai'], 'allow_fallbacks' => true], $openRouterBody['provider']);
        self::assertArrayNotHasKey('provider', $directProbe->buildLoadPayload()['body']);
    }
}
