<?php

declare(strict_types=1);

/**
 * The catalog is read through DDD's Config, and framework objects expect a booted kernel. The suite installs a
 * minimal container and registers this module's config directory — enough for Config::get('AI.models') and for
 * AIModelsService to resolve names without a kernel.
 */

use DDD\Infrastructure\Libs\Config;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

$moduleRoot = dirname(__DIR__);
require $moduleRoot . '/vendor/autoload.php';

if (!defined('APP_ROOT_DIR')) {
    define('APP_ROOT_DIR', $moduleRoot);
}

class TestContainer extends Container
{
    protected array $testInstances = [];

    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if (!isset($this->testInstances[$id])) {
            if (!class_exists($id)) {
                return null;
            }
            $reflectionClass = new ReflectionClass($id);
            $this->testInstances[$id] = $reflectionClass->getConstructor()
            && $reflectionClass->getConstructor()->getNumberOfRequiredParameters() > 0
                ? $reflectionClass->newInstanceWithoutConstructor()
                : new $id();
        }
        return $this->testInstances[$id];
    }

    public function has(string $id): bool
    {
        return class_exists($id) || parent::has($id);
    }
}

$testCacheDir = sys_get_temp_dir() . '/ddd_ai_phpunit_cache';
@mkdir($testCacheDir, 0777, true);
@touch($testCacheDir . '/service_class_map.php');

(new ReflectionProperty(DDD\DDDBundle::class, 'defaultContainer'))->setValue(null, new TestContainer(new ParameterBag([
    'kernel.cache_dir' => $testCacheDir,
    'kernel.project_dir' => $moduleRoot,
    'kernel.environment' => 'test',
])));

Config::addConfigDirectory($moduleRoot . '/config/app', isModule: true);

/** @return array The model catalog as the package ships it */
function dddAiModelCatalog(): array
{
    return require dirname(__DIR__) . '/config/app/AI/models.php';
}
