<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Toon\DecodeOptions;
use Toon\Exception\ToonException;
use Toon\Toon;

/**
 * Runs the official decode fixtures (TOON -> JSON).
 */
final class DecodeConformanceTest extends ConformanceTestCase
{
    /** @return iterable<string, array{string, \stdClass}> */
    public static function provideDecodeCases(): iterable
    {
        return self::fixtureCases('decode');
    }

    #[DataProvider('provideDecodeCases')]
    public function testDecode(string $fixtureFile, \stdClass $test): void
    {
        $options = self::mapOptions($test->options ?? null);

        if ($test->shouldError ?? false) {
            $this->expectException(ToonException::class);
            Toon::decode($test->input, $options);
            return;
        }

        $actual = Toon::decode($test->input, $options);
        self::assertJsonEquivalent($test->expected, $actual);
    }

    private static function mapOptions(?\stdClass $raw): DecodeOptions
    {
        if ($raw === null) {
            return new DecodeOptions();
        }

        return new DecodeOptions(
            indent: $raw->indent ?? 2,
            strict: $raw->strict ?? true,
            expandPaths: $raw->expandPaths ?? 'off',
        );
    }
}
