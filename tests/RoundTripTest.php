<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Toon\DecodeOptions;
use Toon\Delimiter;
use Toon\EncodeOptions;
use Toon\ExpandPaths;
use Toon\KeyFolding;
use Toon\Toon;

/**
 * Property-based round-trip tests: decode(encode(x)) MUST equal x under
 * JSON-model equality (SPEC §2). Values are produced by a deterministic
 * generator seeded with mt_srand(), so every run exercises the exact same
 * corpus — no flaky randomness.
 *
 * Two generator constraints are intentional and spec/host sanctioned (they
 * are NOT library workarounds):
 *
 * - PHP cannot create stdClass properties whose name *starts* with "\0"
 *   (reserved for visibility mangling), so generated keys never start with
 *   NUL. Control characters in any other key position are exercised.
 * - Tabular encoding (SPEC §9.3) declares field order once, from the first
 *   object ("order per object MAY vary"), so per-object key order is not
 *   round-trippable by design for arrays qualifying for tabular form. The
 *   generator canonicalizes such arrays to a single key order up front.
 */
final class RoundTripTest extends ConformanceTestCase
{
    private const SEED = 20260610;
    private const CASES = 200;
    private const MAX_DEPTH = 5;

    /**
     * String corpus: ASCII, unicode/emoji, C0 controls, structural characters
     * (':', '"', '\\', '[', ']', '{', '}'), all three delimiters, whitespace
     * edges, hyphen edges, keyword/numeric look-alikes and multi-line text.
     */
    private const SPECIAL_STRINGS = [
        '', ' ', '  x', 'x  ', ' a b ', 'hello', 'Hello 世界 👋', 'emoji 🎉🎊', '👨‍👩‍👧‍👦',
        "\x00", "\x01\x02", "\x1f", "ctrl\x07bell", "\x0b", "\x0c", "\x7f",
        ':', 'a:b', 'a: b', ': x', '"', '"quoted"', '""', '\\', 'back\\slash', 'end\\',
        '[', ']', '{', '}', '[]', '{}', 'a[1]', '{x}', '[0]:', '[2]: a,b', 'k[1]: v',
        ',', 'a,b', ',,', '|', 'a|b', '||', "\t", "a\tb",
        '-', '-x', '- x', '--', '- [0]:', '- a: 1',
        'true', 'false', 'null', 'True', 'NULL',
        '42', '-3.14', '05', '0', '-0', '1e-6', '1E+9', '0.5', '.5', '+1', '1.', '0x10',
        'Infinity', 'NaN', '1_000', '0e1', '00.5', '9223372036854775808',
        "line1\nline2", "a\r\nb", "\n", "\r", "trail\n", "\nlead",
        'a b', 'a  b', "a\u{00A0}b", "\u{00A0}start", "\u{FEFF}", 'naïve', 'Ωμέγα', '中文', '🙂',
        'users[2]{id,name}:', 'key: value',
    ];

    /**
     * Key corpus: identifiers, dotted keys, spaces, unicode, quotes, the
     * empty-string key, numeric-like keys, delimiter/structural characters.
     * (No key starts with "\0" — see class docblock.)
     */
    private const SPECIAL_KEYS = [
        'key', '_private', 'a.b', 'dot.', '.lead', 'with space', 'ключ', '名前', 'emoji🙂',
        '"q"', '', '42', '05', '-flag', 'true', 'null', 'a:b', 'a,b', 'a|b', "a\tb",
        "nl\nkey", "a\x00b", 'x[1]', '{y}', 'kebab-case', '0', ' ', 'k"k', "k\\k", 'a]b',
    ];

    /** Keys for the folding variant: every key matches ^[A-Za-z_][A-Za-z0-9_]*$. */
    private const IDENTIFIER_KEYS = [
        'a', 'b', 'c', 'd', 'key', 'value', '_x', 'Az9', 'snake_case', 'CAPS', 'n1', 'deep',
    ];

    private const SPECIAL_INTS = [
        0, -1, 1, 7, -42, 1000000, PHP_INT_MAX, PHP_INT_MIN + 1, PHP_INT_MIN,
    ];

    private const SPECIAL_FLOATS = [
        1e-7, 1.5e-7, -1e-7, 1e22, -1e22, 1e21, 1e20, 3.0, -3.0, 0.1, -0.0, 0.5, -2.5,
        1e-6, 0.000001, 0.30000000000000004, 5e-324, 1.7976931348623157e308,
        -123456.789, 1234567890.12345, 9007199254740993.0, 0.0001,
    ];

    /** @var bool When true, generated keys are plain identifiers (folding variant). */
    private bool $identifierKeysOnly = false;

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function provideDelimiters(): iterable
    {
        yield 'comma' => [Delimiter::COMMA];
        yield 'tab' => [Delimiter::TAB];
        yield 'pipe' => [Delimiter::PIPE];
    }

    #[DataProvider('provideDelimiters')]
    public function testRoundTripPerDelimiter(string $delimiter): void
    {
        $this->identifierKeysOnly = false;
        mt_srand(self::SEED);

        $encodeOptions = new EncodeOptions(delimiter: $delimiter);
        $decodeOptions = new DecodeOptions();

        for ($i = 0; $i < self::CASES; $i++) {
            $value = $this->canonicalizeTabular($this->generateValue(0));
            $this->assertRoundTrip($value, $encodeOptions, $decodeOptions, $i);
        }
    }

    public function testRoundTripIndent4(): void
    {
        $this->identifierKeysOnly = false;
        mt_srand(self::SEED + 1);

        $encodeOptions = new EncodeOptions(indent: 4);
        $decodeOptions = new DecodeOptions(indent: 4);

        for ($i = 0; $i < self::CASES; $i++) {
            $value = $this->canonicalizeTabular($this->generateValue(0));
            $this->assertRoundTrip($value, $encodeOptions, $decodeOptions, $i);
        }
    }

    /**
     * keyFolding=safe must be inverted exactly by expandPaths=safe when all
     * generated keys are IdentifierSegments (SPEC §13.4): no generated key
     * contains a dot, so expansion can only ever undo what folding produced.
     */
    public function testRoundTripKeyFoldingWithPathExpansion(): void
    {
        $this->identifierKeysOnly = true;
        mt_srand(self::SEED + 2);

        $encodeOptions = new EncodeOptions(keyFolding: KeyFolding::SAFE);
        $decodeOptions = new DecodeOptions(expandPaths: ExpandPaths::SAFE);

        for ($i = 0; $i < self::CASES; $i++) {
            $value = $this->canonicalizeTabular($this->generateValue(0));
            $this->assertRoundTrip($value, $encodeOptions, $decodeOptions, $i);
        }
    }

    // ------------------------------------------------------------------
    // Round-trip assertion
    // ------------------------------------------------------------------

    private function assertRoundTrip(
        mixed $value,
        EncodeOptions $encodeOptions,
        DecodeOptions $decodeOptions,
        int $case,
    ): void {
        try {
            $encoded = Toon::encode($value, $encodeOptions);
        } catch (\Throwable $e) {
            self::fail(sprintf(
                "encode() threw for case #%d: %s\nValue: %s",
                $case,
                $e->getMessage(),
                self::describe($value),
            ));
        }

        try {
            $decoded = Toon::decode($encoded, $decodeOptions);
        } catch (\Throwable $e) {
            self::fail(sprintf(
                "decode() threw for case #%d: %s\nValue: %s\nTOON document:\n%s",
                $case,
                $e->getMessage(),
                self::describe($value),
                $encoded,
            ));
        }

        self::assertJsonEquivalent($value, $decoded, sprintf('case#%d:$', $case));
    }

    private static function describe(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
    }

    // ------------------------------------------------------------------
    // Deterministic value generator (JSON model, depth <= MAX_DEPTH)
    // ------------------------------------------------------------------

    private function generateValue(int $depth): mixed
    {
        $r = mt_rand(0, 99);
        if ($depth >= self::MAX_DEPTH || $r < 50) {
            return $this->generatePrimitive();
        }
        if ($r < 78) {
            return $this->generateArray($depth);
        }

        return $this->generateObject($depth);
    }

    private function generatePrimitive(): mixed
    {
        return match (mt_rand(0, 5)) {
            0 => null,
            1 => mt_rand(0, 1) === 1,
            2 => $this->generateInt(),
            3 => $this->generateFloat(),
            default => $this->generateString(),
        };
    }

    private function generateInt(): int
    {
        if (mt_rand(0, 2) === 0) {
            return self::SPECIAL_INTS[mt_rand(0, count(self::SPECIAL_INTS) - 1)];
        }

        return mt_rand(-1000000, 1000000);
    }

    private function generateFloat(): float
    {
        if (mt_rand(0, 1) === 0) {
            return self::SPECIAL_FLOATS[mt_rand(0, count(self::SPECIAL_FLOATS) - 1)];
        }
        $f = (mt_rand() / mt_getrandmax()) * (10 ** mt_rand(-8, 8));

        return mt_rand(0, 1) === 0 ? -$f : $f;
    }

    private function generateString(): string
    {
        if (mt_rand(0, 9) < 6) {
            return self::SPECIAL_STRINGS[mt_rand(0, count(self::SPECIAL_STRINGS) - 1)];
        }

        $chars = [
            'a', 'b', 'Z', '0', '9', '_', '-', ' ', ':', '"', '\\', '[', ']', '{', '}',
            ',', '|', "\t", "\n", "\r", "\x00", "\x1b", 'é', '世', '🙂', '.', 'e', '+',
        ];
        $length = mt_rand(0, 12);
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= $chars[mt_rand(0, count($chars) - 1)];
        }

        return $s;
    }

    /**
     * Draws $count distinct keys (strict mode rejects duplicate sibling keys,
     * SPEC §14.4, so objects must not repeat keys).
     *
     * @return list<string>
     */
    private function generateKeys(int $count): array
    {
        $pool = $this->identifierKeysOnly ? self::IDENTIFIER_KEYS : self::SPECIAL_KEYS;
        $keys = [];
        while (count($keys) < $count) {
            $key = $pool[mt_rand(0, count($pool) - 1)];
            if (in_array($key, $keys, true)) {
                // "!" keeps fallback keys out of the identifier/dotted space.
                $key = ($this->identifierKeysOnly ? 'k' : 'k!') . mt_rand(0, 9999);
                if (in_array($key, $keys, true)) {
                    continue;
                }
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** @return list<mixed> */
    private function generateArray(int $depth): array
    {
        switch (mt_rand(0, 4)) {
            case 0:
                return [];
            case 1: // primitive array -> inline form (§9.1)
                $n = mt_rand(1, 5);
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->generatePrimitive();
                }

                return $out;
            case 2: // uniform object list -> tabular form (§9.3)
                $keys = $this->generateKeys(mt_rand(1, 4));
                $n = mt_rand(1, 4);
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $object = new \stdClass();
                    foreach ($keys as $key) {
                        $object->{$key} = $this->generatePrimitive();
                    }
                    $out[] = $object;
                }

                return $out;
            case 3: // array of arrays -> expanded list (§9.2)
                $n = mt_rand(1, 3);
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->generateArray($depth + 1);
                }

                return $out;
            default: // mixed array -> expanded list (§9.4, §10)
                $n = mt_rand(1, 4);
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $out[] = $this->generateValue($depth + 1);
                }

                return $out;
        }
    }

    private function generateObject(int $depth): \stdClass
    {
        $object = new \stdClass();
        foreach ($this->generateKeys(mt_rand(0, 4)) as $key) {
            $object->{$key} = $this->generateValue($depth + 1);
        }

        return $object;
    }

    // ------------------------------------------------------------------
    // Tabular canonicalization (SPEC §9.3 sanctioned normalization)
    // ------------------------------------------------------------------

    /**
     * Rewrites every array that qualifies for tabular form (all elements
     * objects with the same non-empty key set and primitive-only values) so
     * all elements share the first object's key order. SPEC §9.3 allows
     * per-object key order to vary on input while the emitted rows always
     * follow the header's field order, so differing orders cannot round-trip
     * by design; equal orders must.
     */
    private function canonicalizeTabular(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->canonicalizeTabular($item);
            }

            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonicalizeTabular(...), $value);
        if ($value === [] || !$value[0] instanceof \stdClass) {
            return $value;
        }

        $firstKeys = array_map('strval', array_keys(get_object_vars($value[0])));
        if ($firstKeys === []) {
            return $value;
        }
        $expectedSet = $firstKeys;
        sort($expectedSet);

        foreach ($value as $item) {
            if (!$item instanceof \stdClass) {
                return $value;
            }
            $vars = get_object_vars($item);
            $keys = array_map('strval', array_keys($vars));
            sort($keys);
            if ($keys !== $expectedSet) {
                return $value;
            }
            foreach ($vars as $fieldValue) {
                if (!self::isJsonPrimitive($fieldValue)) {
                    return $value;
                }
            }
        }

        $out = [];
        foreach ($value as $item) {
            $vars = get_object_vars($item);
            $reordered = new \stdClass();
            foreach ($firstKeys as $key) {
                $reordered->{$key} = $vars[$key];
            }
            $out[] = $reordered;
        }

        return $out;
    }

    private static function isJsonPrimitive(mixed $value): bool
    {
        return $value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }
}
