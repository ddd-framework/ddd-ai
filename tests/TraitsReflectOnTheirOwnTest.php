<?php

declare(strict_types=1);

namespace DDD\Tests\AI;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * Every trait of the package reflects on its own: no constant and no property default reads a constant of the trait
 * through `self::`. Symfony's debug resource tracking reflects a trait itself (ReflectionClassResource →
 * getDefaultProperties()) when the package's files lie outside the application's vendor directory (a path repository,
 * a linked checkout), and PHP refuses "Cannot access trait constant self::X directly" there: the container build dies.
 */
class TraitsReflectOnTheirOwnTest extends TestCase
{
    public function testEveryTraitOfThePackageReflectsItsConstantsAndPropertyDefaults(): void
    {
        $failures = [];
        $sourceFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        $traitCount = 0;
        foreach ($sourceFiles as $sourceFile) {
            if (!str_ends_with($sourceFile->getFilename(), '.php')) {
                continue;
            }
            $sourceCode = (string)file_get_contents($sourceFile->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $sourceCode, $namespaceMatch) !== 1 || preg_match('/^trait\s+(\w+)/m', $sourceCode, $traitMatch) !== 1) {
                continue;
            }
            $traitName = $namespaceMatch[1] . '\\' . $traitMatch[1];
            try {
                if (!trait_exists($traitName)) {
                    continue;
                }
                $traitCount++;
                $reflectionClass = new ReflectionClass($traitName);
                $reflectionClass->getConstants();
                $reflectionClass->getDefaultProperties();
            } catch (Throwable $throwable) {
                $failures[] = $traitName . ': ' . $throwable->getMessage();
            }
        }
        self::assertGreaterThan(0, $traitCount, 'no trait found below src/');
        self::assertSame([], $failures);
    }
}
