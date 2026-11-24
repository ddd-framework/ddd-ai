<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\AI\Repo\Argus\Traits;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Tests\AI\Support\ArgusLanguageModelProbe;
use DDD\Tests\AI\Support\ArgusLanguageModelProbeOfAnotherAnswerShape;
use DDD\Tests\AI\Support\InMemoryTestCache;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A repo that speaks a wire API the trait does not know reads its answers through three hooks: whether an object is
 * the answer itself, its text and its usage (the calls have their own, `extractToolCallsFromResponse()`). The trait's
 * own reading of Chat Completions and Responses answers is unchanged.
 */
class ArgusAILanguageModelTraitAnswerShapeHooksTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryTestCache::installForArgusCacheGroups();
    }

    public function testAnAnswerOfAnotherShapeIsReadThroughTheHooks(): void
    {
        $argusLanguageModelProbe = $this->useModel(new ArgusLanguageModelProbeOfAnotherAnswerShape());
        $answer = json_decode('{"kind":"reply","parts":["The customer lives in Munich.","Both orders go there."],"counts":{"fresh":2,"read":5869,"written":185,"out":120}}');

        $argusLanguageModelProbe->handleLoadResponse($answer);

        self::assertSame("The customer lives in Munich.\nBoth orders go there.", $argusLanguageModelProbe->appliedResultText);
        self::assertNull($argusLanguageModelProbe->getLastLoadFailureReason());
        self::assertSame(6056, $argusLanguageModelProbe->getLastInputTokens(), 'the booked input is the whole input: fresh + read + written');
        self::assertSame(120, $argusLanguageModelProbe->getLastOutputTokens());
        self::assertSame(5869, $argusLanguageModelProbe->getLastCachedInputTokens());
        self::assertSame(185, $argusLanguageModelProbe->getLastCacheWriteTokens());
    }

    public function testAnAnswerOfAnotherShapeWithCallsOnlyIsNoFailure(): void
    {
        $argusLanguageModelProbe = $this->useModel(new ArgusLanguageModelProbeOfAnotherAnswerShape());
        $answer = json_decode('{"kind":"reply","parts":[],"calls":[{"id":"call_1","type":"function","function":{"name":"get_order","arguments":"{}"}}]}');

        $argusLanguageModelProbe->handleLoadResponse($answer);

        self::assertNull($argusLanguageModelProbe->getLastLoadFailureReason());
    }

    public function testWithoutAnOverrideTheOtherShapeIsNoAnswerAndAChatCompletionsAnswerIsReadAsBefore(): void
    {
        $plainProbe = $this->useModel(new ArgusLanguageModelProbe());
        $chatCompletionsAnswer = json_decode('{"choices":[{"message":{"content":"positive"}}],"usage":{"prompt_tokens":40,"completion_tokens":3}}');
        $plainProbe->handleLoadResponse($chatCompletionsAnswer);
        self::assertSame('positive', $plainProbe->appliedResultText);
        self::assertSame(40, $plainProbe->getLastInputTokens());

        $overridingProbe = $this->useModel(new ArgusLanguageModelProbeOfAnotherAnswerShape());
        $sameAnswer = clone $chatCompletionsAnswer;
        $overridingProbe->handleLoadResponse($sameAnswer);
        self::assertSame('positive', $overridingProbe->appliedResultText, 'the hooks fall through to the trait\'s readers');
    }

    public function testTheDefaultReadersBookNoUsageOfAShapeTheyDoNotKnow(): void
    {
        $plainProbe = $this->useModel(new ArgusLanguageModelProbe());
        // an Anthropic Messages answer names its usage like a Responses answer (`input_tokens`, `output_tokens`) and
        // counts it differently: the uncached remainder, with the cache read and the cache write beside it
        $messagesAnswer = json_decode('{"type":"message","role":"assistant","content":[{"type":"text","text":"Done."}],'
            . '"usage":{"input_tokens":2,"cache_read_input_tokens":5869,"cache_creation_input_tokens":185,"output_tokens":120}}');

        $plainProbe->handleLoadResponse($messagesAnswer);

        self::assertNull($plainProbe->appliedResultText, 'the text of a shape the trait does not know is not read');
        self::assertSame('no parseable answer', $plainProbe->getLastLoadFailureReason());
        self::assertNull($plainProbe->getLastInputTokens(), 'two uncached tokens are not booked as the whole input');
        self::assertNull((new ReflectionMethod($plainProbe, 'extractAnswerUsage'))->invoke($plainProbe, $messagesAnswer), 'the usage reader behind the hook refuses the shape');
        self::assertNull((new ReflectionMethod($plainProbe, 'extractAnswerText'))->invoke($plainProbe, $messagesAnswer));
    }

    public function testAResponsesAnswerIsStillReadByTheDefaultReaders(): void
    {
        $plainProbe = $this->useModel(new ArgusLanguageModelProbe());
        $responsesAnswer = json_decode('{"object":"response","output":[{"type":"message","content":[{"type":"output_text","text":"positive"}]}],'
            . '"usage":{"input_tokens":40,"output_tokens":3,"input_tokens_details":{"cached_tokens":30}}}');

        $plainProbe->handleLoadResponse($responsesAnswer);

        self::assertSame('positive', $plainProbe->appliedResultText);
        self::assertSame(40, $plainProbe->getLastInputTokens());
        self::assertSame(30, $plainProbe->getLastCachedInputTokens());
    }

    protected function useModel(ArgusLanguageModelProbe $argusLanguageModelProbe): ArgusLanguageModelProbe
    {
        return $argusLanguageModelProbe->useArgusLanguageModel(new ArgusLanguageModel(
            defaultAIModelName: AIModel::MODEL_ANTHROPIC_CLAUDE_OPUS_5_5,
            temperature: 0.3,
            aiUsageScope: 'TEST.SCOPE',
        ));
    }
}
