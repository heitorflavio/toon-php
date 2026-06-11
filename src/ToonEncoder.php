<?php

declare(strict_types=1);

namespace Toon;

use Toon\Exception\EncodeException;

/**
 * TOON encoder (SPEC §2-§3, §5-§13).
 *
 * Host values are first normalized to the JSON data model (SPEC §3), then
 * serialized line by line: objects via indentation (§8), arrays inline,
 * tabular or as expanded lists (§9-§10), with delimiter-aware quoting (§7, §11)
 * and optional safe key folding (§13.4).
 */
final class ToonEncoder
{
    private const UNQUOTED_KEY_RE = '/^[A-Za-z_][A-Za-z0-9_.]*$/D';
    private const IDENTIFIER_SEGMENT_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const NUMERIC_LIKE_RE = '/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/iD';
    private const BOUNDARY_WHITESPACE_RE = '/^[\s\x{FEFF}]|[\s\x{FEFF}]$/uD';

    public function __construct(
        private readonly EncodeOptions $options = new EncodeOptions(),
    ) {
    }

    public function encode(mixed $value): string
    {
        $normalized = $this->normalize($value);
        $lines = [];

        if ($normalized instanceof \stdClass) {
            $this->emitObjectFields($normalized, 0, $lines);
        } elseif (is_array($normalized)) {
            if ($normalized === []) {
                $lines[] = '[]';
            } else {
                $this->emitArray('', $normalized, 0, $lines);
            }
        } else {
            $lines[] = $this->encodePrimitive($normalized);
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // Host type normalization (SPEC §3)
    // ------------------------------------------------------------------

    /**
     * Normalizes a host value to the JSON model: null|bool|int|float|string,
     * list arrays for JSON arrays and stdClass for JSON objects.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $this->assertUtf8($value, 'string value');
            return $value;
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map($this->normalize(...), $value);
            }
            $object = new \stdClass();
            foreach ($value as $key => $item) {
                $key = (string) $key;
                $this->assertUtf8($key, 'object key');
                $object->{$key} = $this->normalize($item);
            }
            return $object;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalize($value->jsonSerialize());
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if ($value instanceof \BackedEnum) {
            return $this->normalize($value->value);
        }

        if ($value instanceof \UnitEnum) {
            return $this->normalize($value->name);
        }

        if ($value instanceof \Closure) {
            return null;
        }

        if (is_object($value)) {
            $object = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $key = (string) $key;
                $this->assertUtf8($key, 'object key');
                $object->{$key} = $this->normalize($item);
            }
            return $object;
        }

        // Resources and anything else that cannot be represented.
        return null;
    }

    /**
     * Rejects byte strings that are not valid UTF-8, mirroring json_encode:
     * a TOON document is a sequence of UTF-8 text lines (SPEC §1.2, §13.1),
     * so malformed input cannot be encoded conformantly.
     *
     * preg_match('//u', ...) runs PCRE's built-in UTF-8 validity check and
     * fails on invalid byte sequences; embedded NUL bytes are valid UTF-8.
     */
    private function assertUtf8(string $string, string $what): void
    {
        if (preg_match('//u', $string) !== 1) {
            throw new EncodeException("Malformed UTF-8 characters in {$what}");
        }
    }

    // ------------------------------------------------------------------
    // Objects (SPEC §8) and key folding (SPEC §13.4)
    // ------------------------------------------------------------------

    /** @param list<string> $lines */
    private function emitObjectFields(\stdClass $object, int $depth, array &$lines): void
    {
        $vars = get_object_vars($object);
        $siblingKeys = array_map('strval', array_keys($vars));

        foreach ($vars as $key => $value) {
            $this->emitField((string) $key, $value, $depth, $siblingKeys, $lines);
        }
    }

    /**
     * Emits a single object field, applying safe key folding when enabled.
     *
     * @param list<string> $siblingKeys
     * @param list<string> $lines
     */
    private function emitField(string $key, mixed $value, int $depth, array $siblingKeys, array &$lines): void
    {
        $flattenDepth = $this->options->flattenDepth ?? PHP_INT_MAX;

        if ($this->options->keyFolding === KeyFolding::SAFE && $flattenDepth >= 2) {
            // Build the maximal chain of single-key objects (§13.4).
            $segments = [$key];
            $leaf = $value;
            while ($leaf instanceof \stdClass) {
                $vars = get_object_vars($leaf);
                if (count($vars) !== 1) {
                    break;
                }
                $segments[] = (string) array_key_first($vars);
                $leaf = $vars[array_key_first($vars)];
            }

            $length = count($segments);
            // The leaf must be a primitive, an array, or an empty object.
            $leafFoldable = !($leaf instanceof \stdClass && get_object_vars($leaf) !== []);

            if ($length >= 2 && $leafFoldable) {
                $foldCount = min($length, $flattenDepth);
                $foldedSegments = array_slice($segments, 0, $foldCount);

                $allIdentifiers = true;
                foreach ($foldedSegments as $segment) {
                    if (preg_match(self::IDENTIFIER_SEGMENT_RE, $segment) !== 1) {
                        $allIdentifiers = false;
                        break;
                    }
                }

                $foldedKey = implode('.', $foldedSegments);

                if ($allIdentifiers && !in_array($foldedKey, $siblingKeys, true)) {
                    if ($foldCount === $length) {
                        $this->emitKeyValue($foldedKey, $leaf, $depth, $lines);
                    } else {
                        // Partial fold: remaining segments use standard nesting.
                        $lines[] = $this->indent($depth) . $this->encodeKey($foldedKey) . ':';
                        $nestedDepth = $depth + 1;
                        for ($i = $foldCount; $i < $length - 1; $i++) {
                            $lines[] = $this->indent($nestedDepth) . $this->encodeKey($segments[$i]) . ':';
                            $nestedDepth++;
                        }
                        $this->emitKeyValue($segments[$length - 1], $leaf, $nestedDepth, $lines);
                    }
                } else {
                    // Safe-mode checks failed: emit the whole chain unfolded.
                    $nestedDepth = $depth;
                    for ($i = 0; $i < $length - 1; $i++) {
                        $lines[] = $this->indent($nestedDepth) . $this->encodeKey($segments[$i]) . ':';
                        $nestedDepth++;
                    }
                    $this->emitKeyValue($segments[$length - 1], $leaf, $nestedDepth, $lines);
                }
                return;
            }
        }

        $this->emitKeyValue($key, $value, $depth, $lines);
    }

    /** @param list<string> $lines */
    private function emitKeyValue(string $key, mixed $value, int $depth, array &$lines): void
    {
        $encodedKey = $this->encodeKey($key);
        $indent = $this->indent($depth);

        if ($value instanceof \stdClass) {
            $lines[] = $indent . $encodedKey . ':';
            if (get_object_vars($value) !== []) {
                $this->emitObjectFields($value, $depth + 1, $lines);
            }
            return;
        }

        if (is_array($value)) {
            if ($value === []) {
                $lines[] = $indent . $encodedKey . ': []';
                return;
            }
            $this->emitArray($encodedKey, $value, $depth, $lines);
            return;
        }

        $lines[] = $indent . $encodedKey . ': ' . $this->encodePrimitive($value);
    }

    // ------------------------------------------------------------------
    // Arrays (SPEC §9-§10)
    // ------------------------------------------------------------------

    /**
     * Emits a non-empty array in key context ($encodedKey may be '' at root).
     *
     * @param list<mixed> $items
     * @param list<string> $lines
     */
    private function emitArray(string $encodedKey, array $items, int $depth, array &$lines): void
    {
        $indent = $this->indent($depth);
        $delimiter = $this->options->delimiter;
        $header = $encodedKey . '[' . count($items) . $this->delimiterSymbol() . ']';

        // Inline primitive array (§9.1).
        if ($this->allPrimitives($items)) {
            $values = array_map($this->encodePrimitive(...), $items);
            $lines[] = $indent . $header . ': ' . implode($delimiter, $values);
            return;
        }

        // Tabular array of uniform objects (§9.3).
        $fields = $this->tabularFields($items);
        if ($fields !== null) {
            $encodedFields = array_map($this->encodeKey(...), $fields);
            $lines[] = $indent . $header . '{' . implode($delimiter, $encodedFields) . '}:';
            $rowIndent = $this->indent($depth + 1);
            foreach ($items as $item) {
                $vars = get_object_vars($item);
                $cells = [];
                foreach ($fields as $field) {
                    $cells[] = $this->encodePrimitive($vars[$field]);
                }
                $lines[] = $rowIndent . implode($delimiter, $cells);
            }
            return;
        }

        // Expanded list (§9.2, §9.4).
        $lines[] = $indent . $header . ':';
        foreach ($items as $item) {
            $this->emitListItem($item, $depth + 1, $lines);
        }
    }

    /** @param list<string> $lines */
    private function emitListItem(mixed $item, int $depth, array &$lines): void
    {
        $indent = $this->indent($depth);

        // Object list item (§10).
        if ($item instanceof \stdClass) {
            if (get_object_vars($item) === []) {
                $lines[] = $indent . '-';
                return;
            }
            // Render fields at depth +1, then merge the first line onto the
            // hyphen line. Tabular first fields keep their rows at depth +2.
            $itemLines = [];
            $this->emitObjectFields($item, $depth + 1, $itemLines);
            $lines[] = $indent . '- ' . substr($itemLines[0], ($depth + 1) * $this->options->indent);
            $count = count($itemLines);
            for ($i = 1; $i < $count; $i++) {
                $lines[] = $itemLines[$i];
            }
            return;
        }

        // Array list item (§9.2, §9.4).
        if (is_array($item)) {
            $symbol = $this->delimiterSymbol();
            $count = count($item);
            if ($count === 0) {
                $lines[] = $indent . '- [0' . $symbol . ']:';
                return;
            }
            if ($this->allPrimitives($item)) {
                $values = array_map($this->encodePrimitive(...), $item);
                $lines[] = $indent . '- [' . $count . $symbol . ']: '
                    . implode($this->options->delimiter, $values);
                return;
            }
            // Nested non-primitive array: expanded list, never tabular (§9.4).
            $lines[] = $indent . '- [' . $count . $symbol . ']:';
            foreach ($item as $child) {
                $this->emitListItem($child, $depth + 1, $lines);
            }
            return;
        }

        // Primitive list item.
        $lines[] = $indent . '- ' . $this->encodePrimitive($item);
    }

    /**
     * Returns the ordered field list when the array qualifies for tabular
     * form (§9.3), or null otherwise.
     *
     * @param non-empty-list<mixed> $items
     * @return list<string>|null
     */
    private function tabularFields(array $items): ?array
    {
        $first = $items[0];
        if (!$first instanceof \stdClass) {
            return null;
        }

        $firstVars = get_object_vars($first);
        if ($firstVars === []) {
            return null;
        }

        $fields = array_map('strval', array_keys($firstVars));
        $expectedSet = $fields;
        sort($expectedSet);

        foreach ($items as $item) {
            if (!$item instanceof \stdClass) {
                return null;
            }
            $vars = get_object_vars($item);
            if (count($vars) !== count($fields)) {
                return null;
            }
            $keys = array_map('strval', array_keys($vars));
            sort($keys);
            if ($keys !== $expectedSet) {
                return null;
            }
            foreach ($vars as $value) {
                if (!$this->isPrimitive($value)) {
                    return null;
                }
            }
        }

        return $fields;
    }

    /** @param list<mixed> $items */
    private function allPrimitives(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->isPrimitive($item)) {
                return false;
            }
        }
        return true;
    }

    private function isPrimitive(mixed $value): bool
    {
        return $value === null || is_string($value) || is_bool($value) || is_int($value) || is_float($value);
    }

    // ------------------------------------------------------------------
    // Primitives, strings and keys (SPEC §2, §7)
    // ------------------------------------------------------------------

    private function encodePrimitive(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->formatFloat($value);
        }

        /** @var string $value */
        return $this->needsQuoting($value) ? $this->quoteString($value) : $value;
    }

    /** Quoting rules for string values (SPEC §7.2, §11.1). */
    private function needsQuoting(string $value): bool
    {
        // Leading/trailing whitespace forces quoting (§7.2). trim() covers the
        // ASCII set (incl. \0 and \x0B); the /u regex extends the check to the
        // Unicode White_Space set (NBSP, Zs spaces, U+2028/29, NEL) plus
        // U+FEFF, matching the reference encoder's JS String.prototype.trim()
        // semantics so Unicode-trimming decoders cannot silently strip data.
        if (
            $value === ''
            || $value !== trim($value)
            || preg_match(self::BOUNDARY_WHITESPACE_RE, $value) === 1
        ) {
            return true;
        }
        if ($value === 'true' || $value === 'false' || $value === 'null') {
            return true;
        }
        if (preg_match(self::NUMERIC_LIKE_RE, $value) === 1) {
            return true;
        }
        if ($value[0] === '-') {
            return true;
        }
        if (strpbrk($value, ":\"\\[]{}") !== false) {
            return true;
        }
        if (preg_match('/[\x00-\x1f]/', $value) === 1) {
            return true;
        }
        return str_contains($value, $this->options->delimiter);
    }

    private function encodeKey(string $key): string
    {
        return preg_match(self::UNQUOTED_KEY_RE, $key) === 1 ? $key : $this->quoteString($key);
    }

    private function quoteString(string $value): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                '\\' => '\\\\',
                '"' => '\"',
                "\n" => '\n',
                "\r" => '\r',
                "\t" => '\t',
            ];
            for ($i = 0; $i < 0x20; $i++) {
                $char = chr($i);
                if (!isset($map[$char])) {
                    $map[$char] = sprintf('\u%04x', $i);
                }
            }
        }

        return '"' . strtr($value, $map) . '"';
    }

    /**
     * Formats a finite float exactly like JavaScript String(n) (SPEC §2):
     * shortest round-trip digits, plain decimal for 1e-6 <= |n| < 1e21,
     * lowercase JS-style exponent otherwise.
     */
    private function formatFloat(float $value): string
    {
        if ($value === 0.0) {
            return '0'; // Covers -0.0 (§2).
        }

        $negative = $value < 0;
        $abs = abs($value);

        // Find the shortest round-trip decimal representation.
        $repr = '';
        for ($precision = 1; $precision <= 17; $precision++) {
            $repr = sprintf('%.' . ($precision - 1) . 'e', $abs);
            if ((float) $repr === $abs) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $repr);
        $exponent = (int) $exponent;
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        if ($digits === '') {
            $digits = '0';
        }
        $k = strlen($digits);
        $n = $exponent + 1; // Decimal point position (ECMA-262 Number::toString).

        if ($n >= $k && $n <= 21) {
            $result = $digits . str_repeat('0', $n - $k);
        } elseif ($n >= 1 && $n <= 21) {
            $result = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif ($n > -6 && $n <= 0) {
            $result = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $e = $n - 1;
            $result = $digits[0]
                . ($k > 1 ? '.' . substr($digits, 1) : '')
                . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
        }

        return $negative ? '-' . $result : $result;
    }

    // ------------------------------------------------------------------
    // Layout helpers (SPEC §6, §12)
    // ------------------------------------------------------------------

    private function indent(int $depth): string
    {
        return str_repeat(' ', $depth * $this->options->indent);
    }

    private function delimiterSymbol(): string
    {
        return $this->options->delimiter === Delimiter::COMMA ? '' : $this->options->delimiter;
    }
}
