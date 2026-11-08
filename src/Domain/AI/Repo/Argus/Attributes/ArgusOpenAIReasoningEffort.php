<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Attributes;

class ArgusOpenAIReasoningEffort
{
    public const string NONE    = 'none';
    public const string MINIMAL = 'minimal';
    public const string LOW     = 'low';
    public const string MEDIUM  = 'medium';
    public const string HIGH    = 'high';
    public const string XHIGH   = 'xhigh';
}
