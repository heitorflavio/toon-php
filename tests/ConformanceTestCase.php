<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for running the official language-agnostic TOON conformance
 * fixtures (https://github.com/toon-format/spec, tests/fixtures).
 */
abstract class ConformanceTestCase extends TestCase
{
    /**
     * Yields [testLabel => [fixtureFile, testCase]] for every test in every
     * fixture file of the given category directory (encode|decode).
     *
     * @return iterable<string, array{string, \stdClass}>
     */
    protected static function fixtureCases(string $category): iterable
    {
        $dir = __DIR__ . '/fixtures/' . $category;
        $files = glob($dir . '/*.json');
        sort($files);

        foreach ($files as $file) {
            $fixture = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            $fileName = basename($file, '.json');

            foreach ($fixture->tests as $i => $test) {
                yield sprintf('%s #%d: %s', $fileName, $i, $test->name) => [$fileName, $test];
            }
        }
    }

    /**
     * Recursive JSON-model equality (SPEC §2): objects compare by ordered key
     * sequence, arrays by ordered elements, numbers by mathematical value.
     */
    protected static function assertJsonEquivalent(mixed $expected, mixed $actual, string $path = '$'): void
    {
        if ($expected instanceof \stdClass) {
            self::assertInstanceOf(\stdClass::class, $actual, "Expected object at {$path}");
            $expectedKeys = array_keys(get_object_vars($expected));
            $actualKeys = array_keys(get_object_vars($actual));
            self::assertSame($expectedKeys, $actualKeys, "Object keys mismatch at {$path}");
            foreach ($expectedKeys as $key) {
                self::assertJsonEquivalent($expected->{$key}, $actual->{$key}, "{$path}.{$key}");
            }
            return;
        }

        if (is_array($expected)) {
            self::assertIsArray($actual, "Expected array at {$path}");
            self::assertCount(count($expected), $actual, "Array length mismatch at {$path}");
            foreach ($expected as $i => $item) {
                self::assertJsonEquivalent($item, $actual[$i], "{$path}[{$i}]");
            }
            return;
        }

        if (is_int($expected) || is_float($expected)) {
            self::assertTrue(
                is_int($actual) || is_float($actual),
                "Expected number at {$path}, got " . get_debug_type($actual)
            );
            if (is_int($expected) && is_int($actual)) {
                self::assertSame($expected, $actual, "Number mismatch at {$path}");
            } else {
                self::assertSame((float) $expected, (float) $actual, "Number mismatch at {$path}");
            }
            return;
        }

        self::assertSame($expected, $actual, "Value mismatch at {$path}");
    }
}
