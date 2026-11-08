<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use PHPUnit\Framework\TestCase;

/** The request bodies the language-model trait builds per egress and declaration */
class ArgusAILanguageModelTraitPayloadTest extends TestCase
{
    public function testAnOpenRouterBoundEndpointGetsTheChatCompletionsBodyWithTheOpenRouterId(): void
    {
        $argusLanguageModelProbe = new ArgusLanguageModelProbe();

        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        // the full body, key order included
        self::assertSame(
            [
                'body' => [
                    'model' => 'openai/gpt-5.4-mini',
                    'max_tokens' => $argusLanguageModelProbe->getAIModel()->settings->maxOutputTokens,
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Classify the text.'],
                        ['role' => 'user', 'content' => 'Some user text.'],
                    ],
                ],
            ],
            $loadPayload
        );
    }

    public function testADirectProviderEndpointGetsTheProviderNativeModelId(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useLoadEndpoint('POST:/ai/openAi/chatCompletions');

        self::assertSame(
            $argusLanguageModelProbe->getAIModel()->externalId,
            $argusLanguageModelProbe->buildLoadPayload()['body']['model']
        );
    }

    public function testJsonModeAddsTheResponseFormat(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
        ));

        self::assertSame(['type' => 'json_object'], $argusLanguageModelProbe->buildLoadPayload()['body']['response_format']);
    }

    public function testADeclaredSystemPromptTakesTheSystemRoleAndTheUserContentIsEnveloped(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            systemPromptName: 'Test.System',
        ))->useSystemPrompt('You are a careful classifier.');

        $messages = $argusLanguageModelProbe->buildLoadPayload()['body']['messages'];

        self::assertSame(['role' => 'system', 'content' => 'You are a careful classifier.'], $messages[0]);
        self::assertSame("Classify the text.\n\n<user-data>Some user text.</user-data>", $messages[1]['content']);
    }

    public function testPreferredProvidersBecomeTheProviderBlockOnlyTowardsOpenRouter(): void
    {
        $argusLanguageModel = new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            preferredProviders: ['provider-a', 'provider-b'],
        );
        $openRouterProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel($argusLanguageModel);
        $directProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(clone $argusLanguageModel)
            ->useLoadEndpoint('POST:/ai/openAi/chatCompletions');

        self::assertSame(['provider-a', 'provider-b'], $openRouterProbe->buildLoadPayload()['body']['provider']['order']);
        self::assertArrayNotHasKey('provider', $directProbe->buildLoadPayload()['body']);
    }

    public function testReasoningEffortUsesTheUnifiedObjectTowardsOpenRouter(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4,
            reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
        ));

        $body = $argusLanguageModelProbe->buildLoadPayload()['body'];

        self::assertSame(['effort' => ArgusOpenAIReasoningEffort::LOW], $body['reasoning']);
        // a reasoning model towards OpenRouter gets max_tokens and temperature, never max_completion_tokens
        self::assertArrayHasKey('max_tokens', $body);
        self::assertArrayHasKey('temperature', $body);
        self::assertArrayNotHasKey('max_completion_tokens', $body);
    }

    public function testAGeminiModelTowardsAGeminiEndpointGetsTheNativeBody(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_GOOGLE_GEMINI_2_5_FLASH,
            responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
        ))->useLoadEndpoint('POST:/ai/gemini/generateContent');

        $body = $argusLanguageModelProbe->buildLoadPayload()['body'];

        self::assertSame([['text' => 'Classify the text.'], ['text' => '<user-data>Some user text.</user-data>']], $body['contents'][0]['parts']);
        self::assertSame('application/json', $body['generation_config']['response_mime_type']);
    }

    public function testAGeminiModelTowardsOpenRouterGetsTheChatCompletionsBody(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_GOOGLE_GEMINI_2_5_FLASH,
        ));

        $body = $argusLanguageModelProbe->buildLoadPayload()['body'];

        self::assertSame('google/gemini-2.5-flash', $body['model']);
        self::assertArrayHasKey('messages', $body);
        self::assertArrayNotHasKey('contents', $body);
    }

    public function testAnImagePartAlreadyResolvedToADataUrlPassesThrough(): void
    {
        $argusLanguageModelProbe = new ArgusLanguageModelProbe();
        $argusLanguageModelProbe->probeUserContent = [
            ['type' => 'text', 'text' => 'What is on the picture?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA']],
        ];

        $messages = $argusLanguageModelProbe->buildLoadPayload()['body']['messages'];

        self::assertSame($argusLanguageModelProbe->probeUserContent, $messages[1]['content']);
    }
}
