<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Toon\Delimiter;
use Toon\EncodeOptions;
use Toon\Exception\ToonException;
use Toon\Toon;

/**
 * Runs the official encode fixtures (JSON -> TOON).
 */
final class EncodeConformanceTest extends ConformanceTestCase
{
    /** @return iterable<string, array{string, \stdClass}> */
    public static function provideEncodeCases(): iterable
    {
        return self::fixtureCases('encode');
    }

    #[DataProvider('provideEncodeCases')]
    public function testEncode(string $fixtureFile, \stdClass $test): void
    {
        $options = self::mapOptions($test->options ?? null);

        if ($test->shouldError ?? false) {
            $this->expectException(ToonException::class);
            Toon::encode($test->input, $options);
            return;
        }

        $actual = Toon::encode($test->input, $options);
        $this->assertSame($test->expected, $actual, $test->note ?? '');
    }

    private static function mapOptions(?\stdClass $raw): EncodeOptions
    {
        if ($raw === null) {
            return new EncodeOptions();
        }

        return new EncodeOptions(
            indent: $raw->indent ?? 2,
            delimiter: $raw->delimiter ?? Delimiter::COMMA,
            keyFolding: $raw->keyFolding ?? 'off',
            flattenDepth: $raw->flattenDepth ?? null,
        );
    }
}
