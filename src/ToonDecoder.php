<?php

declare(strict_types=1);

namespace Toon;

use Toon\Exception\DecodeException;
use Toon\Internal\Decoder\Header;
use Toon\Internal\Decoder\HeaderSyntaxError;
use Toon\Internal\Decoder\Line;
use Toon\Internal\Decoder\ObjNode;

/**
 * TOON decoder (SPEC §4-§14).
 *
 * Decoding notes (PHP mapping, see .spec/PHP-DESIGN-NOTES.md):
 * - Objects decode to stdClass by default, or to associative arrays when
 *   DecodeOptions::$associative is true (in that mode an empty object and an
 *   empty array are both the PHP value []).
 * - Integer-looking tokens decode to PHP int when within PHP_INT_MIN..PHP_INT_MAX,
 *   otherwise to float (like json_decode). Tokens with '.' or an exponent
 *   decode to float.
 * - Non-strict tab policy (SPEC §12): tabs in indentation are tolerated and
 *   each tab counts as a single space for depth computation.
 */
final class ToonDecoder
{
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*$/';
    private const IDENTIFIER_SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const NUMBER_PATTERN = '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/';

    /** @var list<Line> */
    private array $lines = [];

    /** @var list<int> Line numbers of blank lines (ascending). */
    private array $blankLines = [];

    private int $pos = 0;

    public function __construct(
        private readonly DecodeOptions $options = new DecodeOptions(),
    ) {
    }

    public function decode(string $toon): mixed
    {
        $this->scanLines($toon);
        $this->pos = 0;

        $value = $this->parseRoot();

        if ($this->options->expandPaths === ExpandPaths::SAFE) {
            $value = $this->expandValue($value);
        }

        return $this->toPublic($value);
    }

    // ------------------------------------------------------------------
    // Line scanning (SPEC §12)
    // ------------------------------------------------------------------

    private function scanLines(string $toon): void
    {
        $this->lines = [];
        $this->blankLines = [];
        $strict = $this->options->strict;
        $indentSize = $this->options->indent;

        foreach (explode("\n", $toon) as $i => $raw) {
            $no = $i + 1;
            if (str_ends_with($raw, "\r")) {
                $raw = substr($raw, 0, -1);
            }
            if (trim($raw, " \t") === '') {
                // Blank regardless of leading whitespace; never validated (§12).
                $this->blankLines[] = $no;
                continue;
            }

            preg_match('/^[ \t]*/', $raw, $m);
            $ws = $m[0];
            $content = substr($raw, strlen($ws));

            if ($strict && str_contains($ws, "\t")) {
                throw new DecodeException('Tabs are not allowed in indentation', $no);
            }
            $spaces = strlen($ws);
            if ($strict && $spaces % $indentSize !== 0) {
                throw new DecodeException(
                    sprintf('Indentation of %d space(s) is not a multiple of %d', $spaces, $indentSize),
                    $no
                );
            }

            $this->lines[] = new Line($no, intdiv($spaces, $indentSize), rtrim($content, ' '));
        }
    }

    private function hasBlankBetween(int $afterLine, int $beforeLine): bool
    {
        foreach ($this->blankLines as $no) {
            if ($no >= $beforeLine) {
                break;
            }
            if ($no > $afterLine) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Root form discovery (SPEC §5)
    // ------------------------------------------------------------------

    private function parseRoot(): mixed
    {
        if ($this->lines === []) {
            return new ObjNode();
        }

        $first = $this->lines[0];
        $count = count($this->lines);

        if ($first->depth === 0 && $count === 1 && $first->content === '[]') {
            return [];
        }

        if ($first->depth === 0 && $first->content[0] === '[') {
            $header = null;
            try {
                $header = $this->parseHeader($first->content, 0, $first->no);
            } catch (HeaderSyntaxError $e) {
                if ($this->options->strict) {
                    throw new DecodeException($e->getMessage(), $first->no);
                }
            }
            if ($header !== null) {
                $value = $this->parseArrayBody($header, 0, $first->no);
                if ($this->pos < $count) {
                    throw new DecodeException('Unexpected content after root array', $this->lines[$this->pos]->no);
                }

                return $value;
            }
        } elseif ($first->depth === 0 && $count === 1 && !$this->isKeyedLine($first->content)) {
            return $this->parsePrimitiveToken($first->content, $first->no);
        }

        $object = $this->parseObjectFields(0);
        if ($this->pos < $count) {
            throw new DecodeException('Unexpected content after document end', $this->lines[$this->pos]->no);
        }

        return $object;
    }

    /**
     * Does this line look like a key-value line or keyed array header
     * (i.e. "key: ..." / "key[...]...") rather than a bare primitive?
     */
    private function isKeyedLine(string $content): bool
    {
        if ($content[0] === '"') {
            try {
                [, $after] = $this->parseQuotedToken($content, 0, 0);
            } catch (DecodeException) {
                return false;
            }

            return $after < strlen($content) && ($content[$after] === ':' || $content[$after] === '[');
        }

        return $this->findUnquoted($content, ':') !== null;
    }

    // ------------------------------------------------------------------
    // Objects (SPEC §8)
    // ------------------------------------------------------------------

    private function parseObjectFields(int $depth): ObjNode
    {
        $node = new ObjNode();
        $total = count($this->lines);

        while ($this->pos < $total) {
            $line = $this->lines[$this->pos];
            if ($line->depth < $depth) {
                break;
            }
            if ($line->depth > $depth) {
                throw new DecodeException('Unexpected indentation', $line->no);
            }
            $this->parseField($node, $line);
        }

        return $node;
    }

    private function parseField(ObjNode $node, Line $line): void
    {
        $c = $line->content;
        $no = $line->no;

        if ($c[0] === '"') {
            [$key, $after] = $this->parseQuotedToken($c, 0, $no);
            $quoted = true;
            if ($after >= strlen($c) || ($c[$after] !== ':' && $c[$after] !== '[')) {
                throw new DecodeException(sprintf('Missing colon after key "%s"', $key), $no);
            }
        } else {
            $colon = $this->findUnquoted($c, ':');
            $bracket = strpos($c, '[');
            if ($bracket !== false && ($colon === null || $bracket < $colon)) {
                $after = $bracket;
            } elseif ($colon !== null) {
                $after = $colon;
            } else {
                throw new DecodeException(sprintf('Missing colon in line "%s"', $c), $no);
            }
            $key = trim(substr($c, 0, $after));
            $quoted = false;
            if ($key === '') {
                throw new DecodeException('Missing key before ' . ($c[$after] === '[' ? 'array header' : 'colon'), $no);
            }
        }

        if ($c[$after] === '[') {
            try {
                $header = $this->parseHeader($c, $after, $no);
            } catch (HeaderSyntaxError $e) {
                if ($this->options->strict) {
                    throw new DecodeException($e->getMessage(), $no);
                }
                // §6 non-strict fall-through: parse as a key-value line whose
                // key is the literal token before the first unquoted colon.
                $colon = $this->findUnquoted($c, ':');
                if ($colon === null) {
                    throw new DecodeException(sprintf('Missing colon in line "%s"', $c), $no);
                }
                $key = trim(substr($c, 0, $colon));
                $value = $this->parseFieldValue(trim(substr($c, $colon + 1), ' '), $line);
                $this->setField($node, $key, $value, false, $no);

                return;
            }
            $value = $this->parseArrayBody($header, $line->depth, $no);
            $this->setField($node, $key, $value, $quoted, $no);

            return;
        }

        $rest = trim(substr($c, $after + 1), ' ');
        $value = $this->parseFieldValue($rest, $line);
        $this->setField($node, $key, $value, $quoted, $no);
    }

    /**
     * Parses the value part of a "key: ..." line, consuming the line itself
     * and (for nested objects) all child lines.
     */
    private function parseFieldValue(string $rest, Line $line): mixed
    {
        $this->pos++;

        if ($rest === '') {
            $next = $this->lines[$this->pos] ?? null;
            if ($next !== null && $next->depth > $line->depth) {
                if ($next->depth > $line->depth + 1) {
                    throw new DecodeException('Unexpected indentation', $next->no);
                }

                return $this->parseObjectFields($line->depth + 1);
            }

            return new ObjNode();
        }

        if ($rest === '[]') {
            return [];
        }

        return $this->parsePrimitiveToken($rest, $line->no);
    }

    private function setField(ObjNode $node, string $key, mixed $value, bool $quoted, int $no): void
    {
        if ($node->has($key) && $this->options->strict) {
            throw new DecodeException(sprintf('Duplicate key "%s"', $key), $no);
        }
        // Non-strict: last-write-wins, silently (SPEC §14.4).
        $node->set($key, $value, $quoted);
    }

    // ------------------------------------------------------------------
    // Array headers (SPEC §6)
    // ------------------------------------------------------------------

    /**
     * @throws HeaderSyntaxError
     */
    private function parseHeader(string $c, int $bracketPos, int $no): Header
    {
        $close = strpos($c, ']', $bracketPos);
        if ($close === false) {
            throw new HeaderSyntaxError('Unterminated bracket segment in array header');
        }
        $inner = substr($c, $bracketPos + 1, $close - $bracketPos - 1);

        $delimiter = ',';
        if ($inner !== '' && (str_ends_with($inner, "\t") || str_ends_with($inner, '|'))) {
            $delimiter = substr($inner, -1);
            $inner = substr($inner, 0, -1);
        }
        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $inner) !== 1) {
            throw new HeaderSyntaxError(sprintf('Invalid array length "[%s]" in header', $inner));
        }

        $i = $close + 1;
        $len = strlen($c);
        $fields = null;

        if ($i < $len && $c[$i] === '{') {
            $closeBrace = $this->findUnquoted(substr($c, $i + 1), '}');
            if ($closeBrace === null) {
                throw new HeaderSyntaxError('Unterminated field list in array header');
            }
            $fields = $this->parseFieldNames(substr($c, $i + 1, $closeBrace), $delimiter, $no);
            $i += $closeBrace + 2;
        }

        if ($i >= $len || $c[$i] !== ':') {
            throw new HeaderSyntaxError(
                $i >= $len
                    ? 'Missing colon after array header'
                    : 'Unexpected content between array header and colon'
            );
        }

        return new Header((int) $inner, $delimiter, $fields, trim(substr($c, $i + 1), ' '));
    }

    /**
     * @return list<array{string, bool}>
     *
     * @throws HeaderSyntaxError
     */
    private function parseFieldNames(string $fieldsStr, string $delimiter, int $no): array
    {
        $fields = [];
        foreach ($this->splitDelimited($fieldsStr, $delimiter) as $token) {
            if ($token !== '' && $token[0] === '"') {
                try {
                    [$name, $after] = $this->parseQuotedToken($token, 0, $no);
                } catch (DecodeException $e) {
                    throw new HeaderSyntaxError($e->getMessage());
                }
                if ($after !== strlen($token)) {
                    throw new HeaderSyntaxError('Unexpected characters after quoted field name');
                }
                $fields[] = [$name, true];
            } else {
                if ($this->options->strict && preg_match(self::KEY_PATTERN, $token) !== 1) {
                    throw new HeaderSyntaxError(
                        sprintf('Invalid unquoted field name "%s" in array header (delimiter mismatch?)', $token)
                    );
                }
                $fields[] = [$token, false];
            }
        }

        return $fields;
    }

    // ------------------------------------------------------------------
    // Array bodies (SPEC §9)
    // ------------------------------------------------------------------

    /**
     * Parses the array introduced by $header, whose header line (currently at
     * $this->pos) sits at $depth. Rows/list items are read at $depth + 1.
     *
     * @return list<mixed>
     */
    private function parseArrayBody(Header $header, int $depth, int $no): array
    {
        $this->pos++; // consume the header line
        $strict = $this->options->strict;
        $total = count($this->lines);

        // Tabular form (§9.3)
        if ($header->fields !== null) {
            if ($header->rest !== '') {
                throw new DecodeException('Unexpected inline content after tabular array header', $no);
            }
            $fieldCount = count($header->fields);
            $rows = [];
            while ($this->pos < $total) {
                $line = $this->lines[$this->pos];
                if ($line->depth !== $depth + 1 || !$this->isRowLine($line->content, $header->delimiter)) {
                    break;
                }
                if ($rows !== [] && $strict
                    && $this->hasBlankBetween($this->lines[$this->pos - 1]->no, $line->no)
                ) {
                    throw new DecodeException('Blank line inside tabular array', $line->no);
                }
                $tokens = $this->splitDelimited($line->content, $header->delimiter);
                if ($strict && count($tokens) !== $fieldCount) {
                    throw new DecodeException(
                        sprintf('Tabular row has %d value(s), expected %d', count($tokens), $fieldCount),
                        $line->no
                    );
                }
                $row = new ObjNode();
                foreach ($header->fields as $i => [$name, $quoted]) {
                    if ($i < count($tokens)) {
                        $row->set($name, $this->parsePrimitiveToken($tokens[$i], $line->no), $quoted);
                    }
                }
                $rows[] = $row;
                $this->pos++;
            }
            if ($strict && count($rows) !== $header->length) {
                throw new DecodeException(
                    sprintf('Expected %d tabular row(s), got %d', $header->length, count($rows)),
                    $no
                );
            }

            return $rows;
        }

        // Inline primitive array (§9.1)
        if ($header->rest !== '') {
            $tokens = $this->splitDelimited($header->rest, $header->delimiter);
            if ($strict && count($tokens) !== $header->length) {
                throw new DecodeException(
                    sprintf('Expected %d inline value(s), got %d', $header->length, count($tokens)),
                    $no
                );
            }
            $values = [];
            foreach ($tokens as $token) {
                $values[] = $this->parsePrimitiveToken($token, $no);
            }

            return $values;
        }

        // Expanded list (§9.2, §9.4) - possibly empty (legacy "key[0]:")
        $items = [];
        while ($this->pos < $total) {
            $line = $this->lines[$this->pos];
            if ($line->depth !== $depth + 1) {
                break;
            }
            $c = $line->content;
            if ($c !== '-' && !str_starts_with($c, '- ')) {
                break;
            }
            if ($items !== [] && $strict
                && $this->hasBlankBetween($this->lines[$this->pos - 1]->no, $line->no)
            ) {
                throw new DecodeException('Blank line inside array', $line->no);
            }
            $items[] = $this->parseListItem($line);
        }
        if ($strict && count($items) !== $header->length) {
            throw new DecodeException(
                sprintf('Expected %d list item(s), got %d', $header->length, count($items)),
                $no
            );
        }

        return $items;
    }

    /**
     * Parses a single "- ..." list item (SPEC §9.4, §10). The hyphen line is
     * at $line->depth; per §10, the first field of an object item behaves as
     * if written at depth + 1.
     */
    private function parseListItem(Line $line): mixed
    {
        $no = $line->no;
        $depth = $line->depth;
        $rest = $line->content === '-' ? '' : ltrim(substr($line->content, 2), ' ');

        if ($rest === '') {
            // Bare "-": empty object list item (§10).
            $this->pos++;

            return new ObjNode();
        }

        if ($rest[0] === '[') {
            $header = null;
            try {
                $header = $this->parseHeader($rest, 0, $no);
            } catch (HeaderSyntaxError $e) {
                if ($this->options->strict) {
                    throw new DecodeException($e->getMessage(), $no);
                }
            }
            if ($header !== null) {
                // Inner array: nested items live at depth + 1 relative to the hyphen line (§9.4).
                return $this->parseArrayBody($header, $depth, $no);
            }
        }

        if ($this->isKeyedLine($rest)) {
            // Object with its first field on the hyphen line: re-frame the
            // remainder as a virtual line at depth + 1 (§10), so tabular rows
            // land at depth + 2 and sibling fields at depth + 1.
            $this->lines[$this->pos] = new Line($no, $depth + 1, $rest);

            return $this->parseObjectFields($depth + 1);
        }

        $this->pos++;

        return $this->parsePrimitiveToken($rest, $no);
    }

    /**
     * Row vs key-value disambiguation at row depth (SPEC §9.3).
     */
    private function isRowLine(string $content, string $delimiter): bool
    {
        $colon = $this->findUnquoted($content, ':');
        if ($colon === null) {
            return true;
        }
        $delim = $this->findUnquoted($content, $delimiter);

        return $delim !== null && $delim < $colon;
    }

    // ------------------------------------------------------------------
    // Tokens (SPEC §4, §7, §11.2)
    // ------------------------------------------------------------------

    /**
     * Splits on unquoted occurrences of the active delimiter, preserving
     * empty tokens and trimming surrounding whitespace (SPEC §11.2, B.3).
     *
     * @return list<string>
     */
    private function splitDelimited(string $s, string $delimiter): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inQuotes) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $ch . $s[$i + 1];
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inQuotes = false;
                }
                $current .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inQuotes = true;
                $current .= $ch;
                continue;
            }
            if ($ch === $delimiter) {
                $tokens[] = trim($current, " \t");
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $tokens[] = trim($current, " \t");

        return $tokens;
    }

    /**
     * First unquoted occurrence of a single-character needle, or null.
     */
    private function findUnquoted(string $s, string $needle): ?int
    {
        $inQuotes = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inQuotes) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inQuotes = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inQuotes = true;
                continue;
            }
            if ($ch === $needle) {
                return $i;
            }
        }

        return null;
    }

    private function parsePrimitiveToken(string $token, int $no): mixed
    {
        if ($token === '') {
            return '';
        }
        if ($token[0] === '"') {
            [$value, $after] = $this->parseQuotedToken($token, 0, $no);
            if ($after !== strlen($token)) {
                throw new DecodeException('Unexpected characters after closing quote', $no);
            }

            return $value;
        }
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }
        if ($token === 'null') {
            return null;
        }
        if (preg_match(self::NUMBER_PATTERN, $token) === 1) {
            return $this->parseNumber($token);
        }

        return $token;
    }

    private function parseNumber(string $token): int|float
    {
        if (strpbrk($token, '.eE') === false) {
            // Integer-looking: PHP int when in range, float otherwise (like json_decode).
            $negative = $token[0] === '-';
            $digits = $negative ? substr($token, 1) : $token;
            $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
            if (strlen($digits) < strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0)
            ) {
                return (int) $token; // "-0" yields int 0 (§4)
            }

            return (float) $token;
        }

        $value = (float) $token;

        return $value === 0.0 ? 0.0 : $value; // normalize -0.0 to 0.0 (§4)
    }

    /**
     * Unescapes a quoted string/key starting at $start (SPEC §7.1).
     *
     * @return array{string, int} The decoded value and the offset just past the closing quote.
     */
    private function parseQuotedToken(string $s, int $start, int $no): array
    {
        $len = strlen($s);
        $out = '';
        $i = $start + 1;

        while ($i < $len) {
            $ch = $s[$i];
            if ($ch === '"') {
                return [$out, $i + 1];
            }
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    throw new DecodeException('Unterminated escape sequence', $no);
                }
                $esc = $s[$i + 1];
                switch ($esc) {
                    case '\\':
                        $out .= '\\';
                        break;
                    case '"':
                        $out .= '"';
                        break;
                    case 'n':
                        $out .= "\n";
                        break;
                    case 'r':
                        $out .= "\r";
                        break;
                    case 't':
                        $out .= "\t";
                        break;
                    case 'u':
                        $hex = substr($s, $i + 2, 4);
                        if (preg_match('/^[0-9A-Fa-f]{4}$/', $hex) !== 1) {
                            throw new DecodeException('Invalid \\u escape: expected four hex digits', $no);
                        }
                        $cp = (int) hexdec($hex);
                        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                            throw new DecodeException(sprintf('Lone surrogate \\u%s is not allowed', $hex), $no);
                        }
                        $out .= self::codepointToUtf8($cp);
                        $i += 6;
                        continue 2;
                    default:
                        throw new DecodeException(sprintf('Invalid escape sequence "\\%s"', $esc), $no);
                }
                $i += 2;
                continue;
            }
            $out .= $ch;
            $i++;
        }

        throw new DecodeException('Unterminated string', $no);
    }

    private static function codepointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }

        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    // ------------------------------------------------------------------
    // Path expansion (SPEC §13.4)
    // ------------------------------------------------------------------

    private function expandValue(mixed $value): mixed
    {
        if ($value instanceof ObjNode) {
            return $this->expandNode($value);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->expandValue($item), $value);
        }

        return $value;
    }

    private function expandNode(ObjNode $node): ObjNode
    {
        $out = new ObjNode();
        foreach ($node->entries as $k => $value) {
            $key = (string) $k;
            $value = $this->expandValue($value);
            $quoted = $node->isQuoted($k);
            $segments = !$quoted && str_contains($key, '.') ? $this->expandableSegments($key) : null;
            if ($segments !== null) {
                $this->insertPath($out, $segments, $value);
            } else {
                $this->insertExpanded($out, $key, $value, $quoted);
            }
        }

        return $out;
    }

    /** @return non-empty-list<string>|null */
    private function expandableSegments(string $key): ?array
    {
        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER_SEGMENT, $segment) !== 1) {
                return null;
            }
        }

        return $segments;
    }

    /** @param non-empty-list<string> $segments */
    private function insertPath(ObjNode $node, array $segments, mixed $value): void
    {
        $last = count($segments) - 1;
        $cursor = $node;
        for ($i = 0; $i < $last; $i++) {
            $segment = $segments[$i];
            if ($cursor->has($segment)) {
                $existing = $cursor->entries[$segment];
                if ($existing instanceof ObjNode) {
                    $cursor = $existing;
                    continue;
                }
                if ($this->options->strict) {
                    throw new DecodeException(
                        sprintf("Path expansion conflict at '%s' (object vs non-object)", $segment)
                    );
                }
                // Non-strict LWW: the expanded object replaces the earlier value.
            }
            $child = new ObjNode();
            $cursor->set($segment, $child);
            $cursor = $child;
        }
        $this->insertExpanded($cursor, $segments[$last], $value, false);
    }

    private function insertExpanded(ObjNode $node, string $key, mixed $value, bool $quoted): void
    {
        if ($node->has($key)) {
            $existing = $node->entries[$key];
            if ($existing instanceof ObjNode && $value instanceof ObjNode) {
                $this->mergeNodes($existing, $value);

                return;
            }
            if ($this->options->strict) {
                throw new DecodeException(sprintf("Path expansion conflict at '%s'", $key));
            }
            // Non-strict LWW: fall through and overwrite.
        }
        $node->set($key, $value, $quoted);
    }

    private function mergeNodes(ObjNode $target, ObjNode $source): void
    {
        foreach ($source->entries as $k => $value) {
            $this->insertExpanded($target, (string) $k, $value, $source->isQuoted($k));
        }
    }

    // ------------------------------------------------------------------
    // Public value conversion
    // ------------------------------------------------------------------

    private function toPublic(mixed $value): mixed
    {
        if ($value instanceof ObjNode) {
            if ($this->options->associative) {
                $out = [];
                foreach ($value->entries as $k => $item) {
                    $out[$k] = $this->toPublic($item);
                }

                return $out;
            }
            $out = new \stdClass();
            foreach ($value->entries as $k => $item) {
                $out->{(string) $k} = $this->toPublic($item);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->toPublic($item), $value);
        }

        return $value;
    }
}
