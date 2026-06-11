# TOON for PHP

**TOON (Token-Oriented Object Notation)** is a compact, human-readable serialization of the JSON data model, designed to shrink the token footprint of structured data in LLM prompts. It combines YAML-like indentation for nested objects with a CSV-style tabular layout for uniform arrays: each array declares its length (`[N]`) and its fields (`{a,b,c}`) exactly once, then streams one row per line. On uniform arrays of objects this typically yields **30â€“60% fewer tokens than pretty-printed JSON** while remaining a lossless, drop-in representation â€” use JSON programmatically, encode it as TOON for LLM input. The explicit length and field declarations also act as guardrails that help models parse and validate the data.

This library implements [TOON SPEC v3.3](https://github.com/toon-format/spec) for PHP and passes the official language-agnostic conformance fixture suite. See also the [reference TypeScript implementation](https://github.com/toon-format/toon).

## JSON vs TOON

The same document, side by side:

**JSON** (pretty-printed):

```json
{
  "context": {
    "task": "Summarize sales",
    "region": "EMEA"
  },
  "tags": ["q3", "priority"],
  "orders": [
    { "sku": "A1", "qty": 2, "price": 9.99 },
    { "sku": "B2", "qty": 1, "price": 14.5 },
    { "sku": "C7", "qty": 5, "price": 3.75 }
  ]
}
```

**TOON:**

```
context:
  task: Summarize sales
  region: EMEA
tags[2]: q3,priority
orders[3]{sku,qty,price}:
  A1,2,9.99
  B2,1,14.5
  C7,5,3.75
```

Braces, brackets, repeated field names, and most quotes are gone. The `orders` array declares `[3]` rows and `{sku,qty,price}` fields once, then emits pure data.

## Installation

```bash
composer require heitorflavio/toon-php
```

Requires PHP >= 8.2 (`ext-mbstring`). No other runtime dependencies.

## Quick start

### Encoding

```php
use Toon\Toon;

echo Toon::encode([
    'users' => [
        ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
        ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
    ],
]);
```

```
users[2]{id,name,role}:
  1,Alice,admin
  2,Bob,user
```

### Decoding

```php
use Toon\Toon;
use Toon\DecodeOptions;

$toon = <<<TOON
users[2]{id,name,role}:
  1,Alice,admin
  2,Bob,user
TOON;

// Objects decode to stdClass by default (like json_decode):
$data = Toon::decode($toon);
echo $data->users[0]->name; // Alice

// Or to associative arrays (like json_decode($s, true)):
$data = Toon::decode($toon, new DecodeOptions(associative: true));
echo $data['users'][1]['role']; // user
```

Round-trips are lossless on the JSON data model:

```php
$original = ['a' => [1, 2], 'b' => ['c' => null, 'd' => true]];
$back = Toon::decode(Toon::encode($original), new DecodeOptions(associative: true));
var_export($back === $original); // true
```

## API

```php
Toon::encode(mixed $value, ?EncodeOptions $options = null): string
Toon::decode(string $toon, ?DecodeOptions $options = null): mixed
```

`Toon::encode()` throws `Toon\Exception\EncodeException` (e.g. for malformed UTF-8 strings). `Toon::decode()` throws `Toon\Exception\DecodeException` on syntax or strict-mode violations. Both extend `Toon\Exception\ToonException`.

### `EncodeOptions`

```php
new EncodeOptions(indent: 2, delimiter: Delimiter::COMMA, keyFolding: KeyFolding::OFF, flattenDepth: null)
```

| Option | Type | Default | Meaning |
| ------ | ---- | ------- | ------- |
| `indent` | `int` | `2` | Number of spaces per indentation level (must be >= 1). |
| `delimiter` | `string` | `Delimiter::COMMA` | Delimiter for inline array values and tabular rows: `Delimiter::COMMA` (`,`), `Delimiter::TAB` (`"\t"`) or `Delimiter::PIPE` (`\|`). See [Delimiters](#delimiters). |
| `keyFolding` | `string` | `KeyFolding::OFF` | `KeyFolding::SAFE` collapses chains of single-key objects into dotted paths (`a.b.c: 1`). See [Key folding & path expansion](#key-folding--path-expansion). |
| `flattenDepth` | `?int` | `null` | Maximum number of segments to fold when `keyFolding` is `safe`. `null` means unbounded. |

### `DecodeOptions`

```php
new DecodeOptions(indent: 2, strict: true, expandPaths: ExpandPaths::OFF, associative: false)
```

| Option | Type | Default | Meaning |
| ------ | ---- | ------- | ------- |
| `indent` | `int` | `2` | Expected number of spaces per indentation level (must be >= 1). |
| `strict` | `bool` | `true` | Enforce strict-mode validation (SPEC Â§14). See [Strict mode](#strict-mode). |
| `expandPaths` | `string` | `ExpandPaths::OFF` | `ExpandPaths::SAFE` expands dotted unquoted keys into nested objects (inverse of key folding). |
| `associative` | `bool` | `false` | When `true`, decoded objects become associative arrays. When `false` (default), they become `stdClass`. |

#### Why `associative` defaults to `false`

PHP arrays cannot distinguish an empty object from an empty array â€” `[]` is both. Decoding objects to `stdClass` (like `json_decode()`'s default) preserves the `{}` vs `[]` distinction, so `encode(decode($toon))` is lossless:

```php
echo Toon::encode(['config' => new stdClass(), 'list' => []]);
// config:
// list: []
```

**Caveat with `associative: true`:** an empty TOON object decodes as `[]` (an empty PHP array), exactly like `json_decode($s, true)`. Re-encoding that value produces an empty *array* (`list: []`), not an empty object â€” the distinction is lost. Use the default `stdClass` mode whenever empty objects must survive a round-trip.

#### Number decoding

Integer-looking tokens decode to PHP `int` when they fit in `PHP_INT_MIN..PHP_INT_MAX`, otherwise to `float` (like `json_decode`). Tokens with a fractional part or exponent decode to `float`:

```php
var_dump(Toon::decode('n: 9223372036854775807')->n); // int(9223372036854775807)
var_dump(Toon::decode('n: 9223372036854775808')->n); // float(9.223372036854776E+18)
var_dump(Toon::decode('n: 1.5')->n);                 // float(1.5)
```

## Host-type normalization (PHP â†’ TOON)

Before encoding, PHP values are normalized to the JSON data model (SPEC Â§3):

| PHP value | Encodes as |
| --------- | ---------- |
| `null`, `bool`, `int`, `float`, `string` | The corresponding TOON primitive. |
| Array where `array_is_list()` is `true` | Array. An empty PHP array `[]` is an empty array (same as `json_encode`). |
| Associative array | Object (keys cast to string). |
| `stdClass` | Object, property order preserved. An empty `stdClass` is an empty object. |
| `JsonSerializable` | Result of `jsonSerialize()`, normalized recursively (takes precedence over the rules below). |
| `DateTimeInterface` | ISO 8601 / RFC 3339 string, e.g. `"2026-06-11T10:30:00+00:00"`. |
| Backed enum (`\BackedEnum`) | Its `value`. |
| Pure enum (`\UnitEnum`) | Its `name`. |
| `NAN`, `INF`, `-INF` | `null`. (Float `-0.0` encodes as `0`.) |
| Other objects | Object built from public properties (`get_object_vars()`). |
| Closures, resources | `null`. |
| Strings with invalid UTF-8 | `Toon\Exception\EncodeException` (output must be valid UTF-8 text). |

For example:

```php
enum Status: string { case Active = 'active'; }
enum Color { case Red; }

echo Toon::encode([
    'when' => new DateTimeImmutable('2026-06-11T10:30:00+00:00'),
    'status' => Status::Active,
    'color' => Color::Red,
    'ratio' => NAN,
    'callback' => fn () => 1,
]);
```

```
when: "2026-06-11T10:30:00+00:00"
status: active
color: Red
ratio: null
callback: null
```

Number output follows the spec's canonical form (matching JavaScript's `String(n)`): no trailing `.0`, plain decimals inside the canonical range, lowercase-`e` scientific notation outside it:

```php
echo Toon::encode(['pi' => 3.14, 'big' => 1e21, 'tiny' => 0.0000001, 'whole' => 5.0]);
// pi: 3.14
// big: 1e+21
// tiny: 1e-7
// whole: 5
```

## Delimiters

Inline array values and tabular rows can use comma (default), tab, or pipe. The chosen delimiter is declared inside the bracket header (`[2	]` for tab, `[2|]` for pipe; no symbol means comma), so documents are self-describing and decoding needs no option:

```php
use Toon\Delimiter;
use Toon\EncodeOptions;

$data = ['items' => [
    ['sku' => 'A1', 'name' => 'Anvil, small', 'qty' => 2],
    ['sku' => 'B2', 'name' => 'Rope (10 m)', 'qty' => 1],
]];

echo Toon::encode($data); // comma (default)
```

```
items[2]{sku,name,qty}:
  A1,"Anvil, small",2
  B2,Rope (10 m),1
```

```php
echo Toon::encode($data, new EncodeOptions(delimiter: Delimiter::TAB));
```

```
items[2	]{sku	name	qty}:
  A1	Anvil, small	2
  B2	Rope (10 m)	1
```

```php
echo Toon::encode($data, new EncodeOptions(delimiter: Delimiter::PIPE));
```

```
items[2|]{sku|name|qty}:
  A1|Anvil, small|2
  B2|Rope (10 m)|1
```

**When this matters for tokens:** values are only quoted when they contain the *active* delimiter, so switching delimiters changes how much quoting you pay for. `"Anvil, small"` needs quotes with the comma delimiter but not with tab or pipe. If your data is full of commas (prose, addresses, formatted numbers), tab or pipe usually saves tokens; tab also tends to tokenize cheaply in modern LLM tokenizers.

## Key folding & path expansion

### Key folding (encoder)

With `keyFolding: safe`, chains of single-key objects fold into a dotted path, saving indentation and lines:

```php
use Toon\EncodeOptions;
use Toon\KeyFolding;

$cfg = ['data' => ['metadata' => ['items' => [1, 2, 3]]]];

echo Toon::encode($cfg);
// data:
//   metadata:
//     items[3]: 1,2,3

echo Toon::encode($cfg, new EncodeOptions(keyFolding: KeyFolding::SAFE));
// data.metadata.items[3]: 1,2,3

echo Toon::encode($cfg, new EncodeOptions(keyFolding: KeyFolding::SAFE, flattenDepth: 2));
// data.metadata:
//   items[3]: 1,2,3
```

Folding is *safe* by construction: it only applies when every segment is an identifier (`[A-Za-z_][A-Za-z0-9_]*`, no dots) and the folded key would not collide with an existing sibling key:

```php
echo Toon::encode(['a' => ['b' => 1], 'a.b' => 2], new EncodeOptions(keyFolding: KeyFolding::SAFE));
// a:
//   b: 1
// a.b: 2
```

### Path expansion (decoder)

`expandPaths: safe` is the inverse: unquoted dotted keys whose segments are all identifiers expand back into nested objects (deep-merged in encounter order):

```php
use Toon\DecodeOptions;
use Toon\ExpandPaths;

$opts = new DecodeOptions(expandPaths: ExpandPaths::SAFE, associative: true);

var_export(Toon::decode('data.metadata.items[3]: 1,2,3', $opts));
// array ('data' => array ('metadata' => array ('items' => array (0 => 1, 1 => 2, 2 => 3))))

// Without expandPaths, the dotted key stays literal:
var_export(Toon::decode('data.metadata.items[3]: 1,2,3', new DecodeOptions(associative: true)));
// array ('data.metadata.items' => array (0 => 1, 1 => 2, 2 => 3))

// Quoted keys are never expanded:
var_export(Toon::decode('"a.b": 1', $opts));
// array ('a.b' => 1)
```

In strict mode, expansion conflicts (two paths writing the same leaf) throw a `DecodeException`; in non-strict mode the last write wins.

## Strict mode

Strict mode is on by default and makes the decoder a validator (SPEC Â§14). It rejects, among others:

- **Count mismatches** â€” declared `[N]` vs actual inline values, list items, or tabular rows; row width vs field count:

  ```php
  Toon::decode("items[3]: 1,2");
  // DecodeException: Expected 3 inline value(s), got 2 (line 1)

  Toon::decode("users[2]{id,name}:\n  1,Alice\n  2");
  // DecodeException: Tabular row has 1 value(s), expected 2 (line 3)
  ```

- **Indentation problems** â€” not a multiple of `indent`, or tabs used for indentation:

  ```php
  Toon::decode("items[2]:\n  - 1\n   - 2");
  // DecodeException: Indentation of 3 space(s) is not a multiple of 2 (line 3)

  Toon::decode("a:\n\tb: 1");
  // DecodeException: Tabs are not allowed in indentation (line 2)
  ```

- **Malformed syntax** â€” missing colons, invalid escapes, malformed bracket lengths (`[03]`, `[-1]`, `[bar]`), unterminated strings:

  ```php
  Toon::decode("a: 1\njust some text");
  // DecodeException: Missing colon in line "just some text" (line 2)

  Toon::decode('s: "a\x"');
  // DecodeException: Invalid escape sequence "\x" (line 1)
  ```

- **Duplicate sibling keys** and path-expansion conflicts:

  ```php
  Toon::decode("a: 1\na: 2");
  // DecodeException: Duplicate key "a" (line 2)
  ```

`Toon\Exception\DecodeException` carries the offending line when known:

```php
use Toon\Exception\DecodeException;

try {
    Toon::decode("items[3]:\n  - 1\n  - 2");
} catch (DecodeException $e) {
    echo $e->getMessage();  // Expected 3 list item(s), got 2 (line 1)
    echo $e->lineNumber;    // 1 (?int, null when no line applies)
}
```

With `strict: false`, the decoder is lenient: depth is derived as `floor(spaces / indent)`, blank lines inside arrays are ignored, and duplicate keys / expansion conflicts resolve silently as last-write-wins:

```php
var_export(Toon::decode("a: 1\na: 2", new DecodeOptions(strict: false, associative: true)));
// array ('a' => 2)
```

## Format overview

A 60-second tour (see the [spec](https://github.com/toon-format/spec/blob/main/SPEC.md) for the normative rules):

- **Objects** use indentation instead of braces: `key: value`, nested objects indent one level.
- **Primitive arrays** are inline with a declared length: `tags[2]: q3,priority`.
- **Uniform arrays of objects** (same key set, primitive values) become tables â€” fields declared once, one row per line:

  ```
  users[2]{id,name,role}:
    1,Alice,admin
    2,Bob,user
  ```

- **Mixed or non-uniform arrays** fall back to a hyphenated list:

  ```php
  echo Toon::encode(['items' => [42, ['name' => 'nested'], 'text']]);
  ```

  ```
  items[3]:
    - 42
    - name: nested
    - text
  ```

- **Strings are quoted only when necessary** (empty, leading/trailing whitespace, looks like a boolean/number, contains the active delimiter or structural characters):

  ```php
  echo Toon::encode(['note' => 'hello, world', 'empty' => '', 'looksBool' => 'true', 'looksNum' => '42']);
  ```

  ```
  note: "hello, world"
  empty: ""
  looksBool: "true"
  looksNum: "42"
  ```

- **Any JSON root** works: objects, arrays (`[3]: 1,2,3` at root), or a single scalar line (`hello world`). An empty document is an empty object.
- Output is UTF-8 with LF line endings, no trailing whitespace, and deterministic canonical formatting.

## Conformance

This implementation targets [TOON SPEC v3.3](https://github.com/toon-format/spec) and passes the official language-agnostic conformance fixtures shipped under `tests/fixtures/` â€” 153 encoder and 236 decoder fixture cases. The full test suite (conformance plus PHP-specific unit tests) currently runs **444 tests with 9,817 assertions**, all passing:

```bash
php vendor/phpunit/phpunit/phpunit --no-progress
# OK (444 tests, 9817 assertions)
```

## CLI

The package ships a `toon` binary (installed to `vendor/bin/toon`) for quick conversions between JSON and TOON:

```
toon encode [file|-] [options]    Encode JSON input to TOON
toon decode [file|-] [options]    Decode TOON input to JSON
```

Input is read from the file argument, or from STDIN when the argument is `-` or omitted. Output goes to STDOUT unless `-o`/`--output FILE` is given.

```bash
# Encode JSON to TOON
vendor/bin/toon encode data.json -o data.toon

# Pipe from stdin
echo '{"users":[{"id":1,"name":"Alice","role":"admin"},{"id":2,"name":"Bob","role":"user"}]}' | vendor/bin/toon encode
# users[2]{id,name,role}:
#   1,Alice,admin
#   2,Bob,user

# Decode TOON back to (pretty-printed) JSON; --compact minifies
vendor/bin/toon decode data.toon --compact
```

| Option | Applies to | Meaning |
| ------ | ---------- | ------- |
| `-o, --output FILE` | both | Write output to `FILE` instead of STDOUT. |
| `--indent=N` | both | Spaces per indentation level (default: 2). |
| `--delimiter=D` | encode | `comma`, `tab`, `pipe`, or a literal `,` / `\|` (default: comma). |
| `--key-folding=MODE` | encode | `off` or `safe` (default: off). |
| `--flatten-depth=N` | encode | Max folded key segments with `--key-folding=safe` (default: unlimited). |
| `--no-strict` | decode | Disable strict-mode validation. |
| `--expand-paths=MODE` | decode | `off` or `safe` (default: off). |
| `--compact` | decode | Emit compact JSON instead of pretty-printed. |
| `-h, --help` | â€” | Show help text. |
| `-V, --version` | â€” | Show version information (`toon 0.1.0 (TOON spec v3.3) ...`). |

Exit codes: `0` success, `1` usage error, `2` encode/decode/IO error.

## Development

```bash
git clone https://github.com/heitorflavio/toon-php.git
cd toon-php
composer install
composer test
```

Useful filters while working on one side:

```bash
php vendor/phpunit/phpunit/phpunit --filter EncodeConformanceTest --no-progress
php vendor/phpunit/phpunit/phpunit --filter DecodeConformanceTest --no-progress
```

## License

[MIT](./LICENSE) Â© 2026 Thiago Castagnazzi.

TOON format by [Johann Schopplich](https://github.com/johannschopplich) â€” see the [spec](https://github.com/toon-format/spec) and [reference implementation](https://github.com/toon-format/toon).
