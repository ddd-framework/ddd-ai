<?php

declare(strict_types=1);

namespace DDD\Tests\AI\Support;

use DDD\Infrastructure\Cache\Apc;
use LogicException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

/** A cache group in memory; {@see installForArgusCacheGroups()} puts it under the groups ArgusCache uses */
class InMemoryTestCache extends Apc
{
    /** @var array<string, mixed> */
    public array $valuesByKey = [];

    public static function installForArgusCacheGroups(): void
    {
        static::$instances = [
            self::CACHE_GROUP_APC => new self(['defaultTTL' => 60]),
            self::CACHE_GROUP_REDIS_SENTINEL => new self(['defaultTTL' => 60]),
        ] + (static::$instances ?? []);
    }

    public function get(string ...$keys): mixed
    {
        if (count($keys) > 1) {
            return array_intersect_key($this->valuesByKey, array_flip($keys));
        }
        return $this->valuesByKey[$keys[0]] ?? false;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->valuesByKey[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->valuesByKey[$key]);
    }

    public function getCacheAdapter(): ApcuAdapter
    {
        throw new LogicException('The in-memory test cache has no adapter');
    }
}
