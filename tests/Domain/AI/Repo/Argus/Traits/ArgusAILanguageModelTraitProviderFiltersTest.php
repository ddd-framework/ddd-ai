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

    public function testARetryRotatesOnlyTheOrderAndNeverWidensTheHosts(): void
    {
        $rotatedOnce = AIModelProviderFilters::rotateOrderOfProviderBlock(self::KIMI_PROVIDER_BLOCK, 1);
        self::assertSame(['fireworks', 'together', 'amazon-bedrock', 'deepinfra'], $rotatedOnce['order']);
        $rotatedTwice = AIModelProviderFilters::rotateOrderOfProviderBlock(self::KIMI_PROVIDER_BLOCK, 2);
        self::assertSame(['together', 'amazon-bedrock', 'deepinfra', 'fireworks'], $rotatedTwice['order']);
        foreach (['ignore', 'allow_fallbacks', 'only', 'data_collection', 'require_parameters', 'quantizations'] as $unchangedKey) {
            self::assertSame(self::KIMI_PROVIDER_BLOCK[$unchangedKey], $rotatedTwice[$unchangedKey], $unchangedKey);
        }
        self::assertSame(self::KIMI_PROVIDER_BLOCK, AIModelProviderFilters::rotateOrderOfProviderBlock(self::KIMI_PROVIDER_BLOCK, 0));
        self::assertSame(['order' => ['deepinfra']], AIModelProviderFilters::rotateOrderOfProviderBlock(['order' => ['deepinfra']], 1));
    }

    public function testTheRequestOfARetryCarriesTheRotatedOrder(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO,
        ));
        $argusLanguageModelProbe->providerOrderRotation = 1;

        $providerBlock = $argusLanguageModelProbe->buildLoadPayload()['body']['provider'];

        self::assertSame(['fireworks', 'together', 'amazon-bedrock', 'deepinfra'], $providerBlock['order']);
        self::assertSame(self::KIMI_PROVIDER_BLOCK['only'], $providerBlock['only']);
    }

    public function testTheTransportTimeoutIsACatalogSetting(): void
    {
        $aiModelsService = new AIModelsService();
        self::assertSame(120, $aiModelsService->getAIModelByName(AIModel::MODEL_MOONSHOT_KIMI_K3_1_PRO)->getTransportTimeoutSeconds());
        self::assertSame(AIModel::DEFAULT_TRANSPORT_TIMEOUT_SECONDS, $aiModelsService->getAIModelByName(AIModel::MODEL_OPENAI_GPT5_4_MINI)->getTransportTimeoutSeconds());
    }
}
