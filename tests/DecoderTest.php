<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\TestCase;
use Toon\DecodeOptions;
use Toon\Exception\DecodeException;
use Toon\ExpandPaths;
use Toon\Toon;

/**
 * PHP-specific decoder behavior: object modes (stdClass vs associative),
 * int/float token typing, DecodeException metadata, non-strict leniency,
 * and path-expansion / duplicate-key conflict handling.
 */
final class DecoderTest extends TestCase
{
    // ------------------------------------------------------------------
    // Object modes
    // ------------------------------------------------------------------

    public function testDefaultModeReturnsStdClassAndPreservesEmptyObjectVsEmptyArray(): void
    {
        $result = Toon::decode("user:\nitems: []");

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertInstanceOf(\stdClass::class, $result->user, 'bare "key:" must decode to an empty object');
        self::assertSame([], get_object_vars($result->user));
        self::assertSame([], $result->items, '"key: []" must decode to an empty PHP array');
    }

    public function testDefaultModePreservesKeyEncounterOrder(): void
    {
        $result = Toon::decode("b: 1\na: 2\nc: 3");

        self::assertSame(['b', 'a', 'c'], array_keys(get_object_vars($result)));
    }

    public function testAssociativeModeReturnsNestedAssociativeArrays(): void
    {
        $result = Toon::decode(
            "user:\n  name: Ada\n  tags[2]: a,b\nitems[1]:\n  - id: 1",
            new DecodeOptions(associative: true)
        );

        self::assertSame(
            [
                'user' => ['name' => 'Ada', 'tags' => ['a', 'b']],
                'items' => [['id' => 1]],
            ],
            $result
        );
    }

    public function testAssociativeModeEmptyObjectCaveat(): void
    {
        // Caveat: with associative=true an empty TOON object ("user:") and an
        // empty array ("items: []") both become the same PHP value, [] - the
        // {} vs [] distinction is only preserved in the default stdClass mode.
        $result = Toon::decode("user:\nitems: []", new DecodeOptions(associative: true));

        self::assertSame(['user' => [], 'items' => []], $result);
    }

    // ------------------------------------------------------------------
    // Number typing (PHP int vs float)
    // ------------------------------------------------------------------

    public function testIntegerTokensWithinPhpIntRangeDecodeToInt(): void
    {
        self::assertSame(42, Toon::decode('42'));
        self::assertSame(-7, Toon::decode('n: -7')->n);
        self::assertSame(PHP_INT_MAX, Toon::decode('n: ' . PHP_INT_MAX)->n);
        self::assertSame(PHP_INT_MIN, Toon::decode('n: ' . PHP_INT_MIN)->n);
        self::assertSame(0, Toon::decode('n: -0')->n, '-0 decodes to int 0');
    }

    public function testIntegerTokensBeyondPhpIntRangeDecodeToFloat(): void
    {
        $justOver = Toon::decode('n: 9223372036854775808')->n; // PHP_INT_MAX + 1
        self::assertIsFloat($justOver);
        self::assertSame(9.223372036854776E+18, $justOver);

        $justUnder = Toon::decode('n: -9223372036854775809')->n; // PHP_INT_MIN - 1
        self::assertIsFloat($justUnder);
        self::assertSame(-9.223372036854776E+18, $justUnder);
    }

    public function testTokensWithFractionOrExponentDecodeToFloat(): void
    {
        $result = Toon::decode("a: 3.14\nb: 1e3\nc: -0.0\nd: 5E+00");

        self::assertSame(3.14, $result->a);
        self::assertIsFloat($result->b);
        self::assertSame(1000.0, $result->b);
        self::assertSame(0.0, $result->c, '-0.0 normalizes to 0');
        self::assertIsFloat($result->d);
        self::assertSame(5.0, $result->d);
    }

    public function testLeadingZeroTokensStayStrings(): void
    {
        self::assertSame('05', Toon::decode('n: 05')->n);
        self::assertSame('-007', Toon::decode('n: -007')->n);
        self::assertSame(0.5, Toon::decode('n: 0.5')->n, '0.5 is a valid number');
    }

    // ------------------------------------------------------------------
    // DecodeException metadata
    // ------------------------------------------------------------------

    public function testDecodeExceptionCarriesMessageAndLineNumber(): void
    {
        try {
            Toon::decode("a: 1\na: 2");
            self::fail('Expected DecodeException for duplicate keys in strict mode');
        } catch (DecodeException $e) {
            self::assertSame(2, $e->lineNumber);
            self::assertStringContainsString('Duplicate key "a"', $e->getMessage());
            self::assertStringContainsString('(line 2)', $e->getMessage());
        }
    }

    public function testIndentationErrorReportsOffendingLine(): void
    {
        try {
            Toon::decode("a:\n  b: 1\n   c: 2");
            self::fail('Expected DecodeException for non-multiple indentation');
        } catch (DecodeException $e) {
            self::assertSame(3, $e->lineNumber);
        }
    }

    public function testInvalidEscapeErrorsWithoutLineNumberStillHaveUsefulMessage(): void
    {
        try {
            Toon::decode('s: "bad\\x"');
            self::fail('Expected DecodeException for invalid escape');
        } catch (DecodeException $e) {
            self::assertStringContainsString('escape', strtolower($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Non-strict leniency
    // ------------------------------------------------------------------

    public function testNonStrictIgnoresCountMismatches(): void
    {
        $options = new DecodeOptions(strict: false);

        self::assertSame(['a', 'b'], Toon::decode('tags[3]: a,b', $options)->tags);
        self::assertSame(['a', 'b'], Toon::decode("items[1]:\n  - a\n  - b", $options)->items);
    }

    public function testNonStrictComputesDepthAsFloorOfSpaces(): void
    {
        $result = Toon::decode("a:\n   b:\n     c: 1", new DecodeOptions(strict: false));

        self::assertSame(1, $result->a->b->c);
    }

    public function testNonStrictIgnoresBlankLinesInsideArrays(): void
    {
        $result = Toon::decode("items[2]:\n  - a\n\n  - b", new DecodeOptions(strict: false));

        self::assertSame(['a', 'b'], $result->items);
    }

    public function testNonStrictFallsBackToLiteralKeyForMalformedHeaders(): void
    {
        $result = Toon::decode('foo[bar]: 10', new DecodeOptions(strict: false));

        self::assertSame(10, $result->{'foo[bar]'});
    }

    // ------------------------------------------------------------------
    // Duplicate keys (SPEC §14.4)
    // ------------------------------------------------------------------

    public function testDuplicateSiblingKeysErrorInStrictMode(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Duplicate key');

        Toon::decode("name: Ada\nname: Bob");
    }

    public function testDuplicateSiblingKeysUseLastWriteWinsInNonStrictMode(): void
    {
        $result = Toon::decode("name: Ada\nname: Bob", new DecodeOptions(strict: false));

        self::assertSame('Bob', $result->name);
        self::assertCount(1, get_object_vars($result));
    }

    // ------------------------------------------------------------------
    // Path expansion (SPEC §13.4)
    // ------------------------------------------------------------------

    public function testExpandPathsConflictErrorsInStrictMode(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('conflict');

        Toon::decode("a.b: 1\na: 2", new DecodeOptions(expandPaths: ExpandPaths::SAFE));
    }

    public function testExpandPathsAppliesLastWriteWinsWhenNotStrict(): void
    {
        $options = new DecodeOptions(strict: false, expandPaths: ExpandPaths::SAFE);

        self::assertSame(2, Toon::decode("a.b: 1\na: 2", $options)->a);
        self::assertSame(2, Toon::decode("a: 1\na.b: 2", $options)->a->b);
    }

    public function testExpandPathsWorksInAssociativeMode(): void
    {
        $result = Toon::decode(
            "a.b.c: 1\na.b.d: 2\na.e: 3",
            new DecodeOptions(expandPaths: ExpandPaths::SAFE, associative: true)
        );

        self::assertSame(['a' => ['b' => ['c' => 1, 'd' => 2], 'e' => 3]], $result);
    }

    public function testExpandPathsLeavesQuotedKeysLiteral(): void
    {
        $result = Toon::decode(
            "a.b: 1\n\"c.d\": 2",
            new DecodeOptions(expandPaths: ExpandPaths::SAFE)
        );

        self::assertSame(1, $result->a->b);
        self::assertSame(2, $result->{'c.d'});
    }
}
