<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCallUsage;

/**
 * A language-model repo that speaks a wire API of its own: its answers are `{kind: "reply", parts: [...], counts: {...}}`,
 * read through the trait's hooks for another answer shape.
 */
class ArgusLanguageModelProbeOfAnotherAnswerShape extends ArgusLanguageModelProbe
{
    protected function isAnswerObjectWithoutEnvelope(object $callResponseData): bool
    {
        return ($callResponseData->kind ?? null) === 'reply' || parent::isAnswerObjectWithoutEnvelope($callResponseData);
    }

    protected function extractAnswerText(object $responseObject): ?string
    {
        if (($responseObject->kind ?? null) !== 'reply') {
            return parent::extractAnswerText($responseObject);
        }
        $texts = array_map(static fn (mixed $part): string => (string)$part, (array)($responseObject->parts ?? []));
        return $texts !== [] ? implode("\n", $texts) : null;
    }

    protected function extractAnswerUsage(object $responseObject): ?AIModelCallUsage
    {
        if (($responseObject->kind ?? null) !== 'reply') {
            return parent::extractAnswerUsage($responseObject);
        }
        $read = (int)($responseObject->counts->read ?? 0);
        $written = (int)($responseObject->counts->written ?? 0);
        return new AIModelCallUsage(
            inputTokens: (int)($responseObject->counts->fresh ?? 0) + $read + $written,
            outputTokens: (int)($responseObject->counts->out ?? 0),
            cachedInputTokens: $read,
            cacheWriteTokens: $written,
        );
    }

    protected function extractToolCallsFromResponse(object $responseObject): ?array
    {
        $calls = $responseObject->calls ?? null;
        return is_array($calls) && $calls !== [] ? $calls : parent::extractToolCallsFromResponse($responseObject);
    }
}
