<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** A logger that keeps every line with its level */
class RecordingLogger extends AbstractLogger
{
    /** @var string[] `<level> <message>` of every line */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = $level . ' ' . $message;
    }
}
