<?php

declare(strict_types=1);

namespace DDD\Tests\AI;

use DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase;

/**
 * Every raw sink call of this package goes through a helper of DDD\Infrastructure\Untrusted or is listed in
 * config/untrusted-sinks-allowlist.php (Core skill ddd-untrusted-input-specialist).
 */
class UntrustedSinkScanTest extends UntrustedSinkScanTestCase
{
    protected function getPackageRootDirectory(): string
    {
        return dirname(__DIR__);
    }
}
