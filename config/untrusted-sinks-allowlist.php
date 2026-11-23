<?php

declare(strict_types=1);

/**
 * Raw sink calls of this package that are fine, each with its reason (Core skill ddd-untrusted-input-specialist).
 * Checked by tests/UntrustedSinkScanTest.php.
 */
return [
    ['file' => 'src/Domain/Batch/Services/AI/OpenAIService.php', 'sink' => 'http-client', 'reason' => 'fixed-host client of the OpenAI API'],
    ['file' => 'src/Domain/Batch/Services/AI/OpenRouterService.php', 'sink' => 'http-client', 'reason' => 'fixed-host client of the OpenRouter API'],
];
