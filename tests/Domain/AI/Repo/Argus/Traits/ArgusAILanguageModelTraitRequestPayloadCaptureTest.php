<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIApiMode;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use PHPUnit\Framework\TestCase;

/** `$lastRequestPayload` holds the payload of the last call on every request shape the trait builds */
class ArgusAILanguageModelTraitRequestPayloadCaptureTest extends TestCase
{
    public function testTheChatCompletionsPayloadIsCaptured(): void
    {
        $argusLanguageModelProbe = new ArgusLanguageModelProbe();

        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        self::assertSame($loadPayload, $argusLanguageModelProbe->lastRequestPayload);
    }

    public function testThePerplexityPayloadIsCaptured(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_PERPLEXITY_SONAR,
        ))->useLoadEndpoint('POST:/ai/perplexity/chatCompletions');

        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        self::assertArrayNotHasKey('response_format', $loadPayload['body']);
        self::assertSame($loadPayload['body'], $argusLanguageModelProbe->lastRequestPayload['body']);
    }

    public function testTheGeminiPayloadIsCaptured(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_GOOGLE_GEMINI_2_5_FLASH,
        ))->useLoadEndpoint('POST:/ai/gemini/generateContent');

        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        self::assertArrayHasKey('generation_config', $loadPayload['body']);
        self::assertSame($loadPayload['body'], $argusLanguageModelProbe->lastRequestPayload['body']);
    }

    public function testTheResponsesApiPayloadIsCaptured(): void
    {
        $argusLanguageModelProbe = (new ArgusLanguageModelProbe())->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
            apiMode: ArgusOpenAIApiMode::RESPONSES,
        ))->useLoadEndpoint('POST:/ai/openAi/responses');

        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        self::assertArrayNotHasKey('messages', $loadPayload['body']);
        self::assertSame($loadPayload['body'], $argusLanguageModelProbe->lastRequestPayload['body']);
    }

    public function testACallOnAnotherShapeReplacesTheEarlierChatCompletionsPayload(): void
    {
        $argusLanguageModelProbe = new ArgusLanguageModelProbe();
        $argusLanguageModelProbe->buildLoadPayload();

        $argusLanguageModelProbe->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_GOOGLE_GEMINI_2_5_FLASH,
        ))->useLoadEndpoint('POST:/ai/gemini/generateContent');
        $loadPayload = $argusLanguageModelProbe->buildLoadPayload();

        self::assertArrayNotHasKey('messages', $argusLanguageModelProbe->lastRequestPayload['body']);
        self::assertSame($loadPayload['body'], $argusLanguageModelProbe->lastRequestPayload['body']);
    }
}
