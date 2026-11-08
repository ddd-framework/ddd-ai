<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ModelCalls;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

/**
 * What one model call consumed, as read from the provider's answer: tokens, cache use, web searches and the cost
 * (provider-reported when the answer carries one, else priced from the model catalog).
 */
class AIModelCallUsage extends ValueObject
{
    /** @var int|null Input/prompt tokens; null when the answer reports none */
    public ?int $inputTokens = null;

    /** @var int|null Output/completion tokens; null when the answer reports none */
    public ?int $outputTokens = null;

    /** @var int Prompt tokens served from the provider's cache */
    public int $cachedInputTokens = 0;

    /** @var int Prompt tokens written to the provider's cache */
    public int $cacheWriteTokens = 0;

    /** @var float|null Cache discount in USD as reported by the provider (negative on a write, positive on a read) */
    public ?float $cacheDiscount = null;

    /** @var int Reasoning tokens inside the output tokens (OpenAI Responses API) */
    public int $reasoningOutputTokens = 0;

    /** @var int Web search calls billed for the answer */
    public int $webSearchCallCount = 0;

    /** @var MoneyAmount|null The cost of the call; null while it is not priced yet */
    public ?MoneyAmount $costs = null;

    public function __construct(
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        int $cachedInputTokens = 0,
        int $cacheWriteTokens = 0,
        ?float $cacheDiscount = null,
        int $reasoningOutputTokens = 0,
        int $webSearchCallCount = 0,
        ?MoneyAmount $costs = null,
    ) {
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->cachedInputTokens = $cachedInputTokens;
        $this->cacheWriteTokens = $cacheWriteTokens;
        $this->cacheDiscount = $cacheDiscount;
        $this->reasoningOutputTokens = $reasoningOutputTokens;
        $this->webSearchCallCount = $webSearchCallCount;
        $this->costs = $costs;
        parent::__construct();
    }
}
