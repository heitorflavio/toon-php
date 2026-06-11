<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\TestCase;
use Toon\Delimiter;
use Toon\EncodeOptions;
use Toon\Exception\EncodeException;
use Toon\KeyFolding;
use Toon\Toon;

enum EncoderTestStringEnum: string
{
    case Admin = 'admin';
}

enum EncoderTestIntEnum: int
{
    case Seven = 7;
}

enum EncoderTestUnitEnum
{
    case North;
}

final class EncoderTestMoney implements \JsonSerializable
{
    public function __construct(
        private readonly int $amount,
        private readonly string $currency,
    ) {
    }

    public function jsonSerialize(): mixed
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}

final class EncoderTestPlainObject
{
    public int $id = 1;
    public string $name = 'Ada';
    protected string $secret = 'hidden';
    private string $internal = 'hidden-too';
}

/**
 * PHP-specific encoder behavior (host-type normalization, options).
 */
final class EncoderTest extends TestCase
{
    public function testStdClassInput(): void
    {
        $user = new \stdClass();
        $user->id = 123;
        $user->name = 'Ada';
        $user->tags = ['a', 'b'];

        $this->assertSame("id: 123\nname: Ada\ntags[2]: a,b", Toon::encode($user));
    }

    public function testNestedStdClassInsideArray(): void
    {
        // A single uniform object is still tabular (SPEC 9.3).
        $inner = new \stdClass();
        $inner->x = 1;
        $this->assertSame("items[1]{x}:\n  1", Toon::encode(['items' => [$inner]]));

        // Non-uniform objects fall back to the expanded list form (SPEC 9.4).
        $other = new \stdClass();
        $other->x = 1;
        $other->y = ['a' => 2];
        $this->assertSame(
            "items[1]:\n  - x: 1\n    y:\n      a: 2",
            Toon::encode(['items' => [$other]])
        );
    }

    public function testJsonSerializableIsSerializedFirst(): void
    {
        $value = ['price' => new EncoderTestMoney(100, 'BRL')];

        $this->assertSame("price:\n  amount: 100\n  currency: BRL", Toon::encode($value));
    }

    public function testDateTimeInterfaceBecomesIso8601String(): void
    {
        $at = new \DateTimeImmutable('2026-06-10 12:34:56', new \DateTimeZone('+02:00'));

        // Contains colons, so the value must be quoted (SPEC 7.2).
        $this->assertSame('at: "2026-06-10T12:34:56+02:00"', Toon::encode(['at' => $at]));

        $mutable = new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC'));
        $this->assertSame('at: "2026-01-02T03:04:05+00:00"', Toon::encode(['at' => $mutable]));
    }

    public function testBackedEnumUsesValue(): void
    {
        $this->assertSame('role: admin', Toon::encode(['role' => EncoderTestStringEnum::Admin]));
        $this->assertSame('code: 7', Toon::encode(['code' => EncoderTestIntEnum::Seven]));
    }

    public function testUnitEnumUsesName(): void
    {
        $this->assertSame('dir: North', Toon::encode(['dir' => EncoderTestUnitEnum::North]));
    }

    public function testNanAndInfinityBecomeNull(): void
    {
        $this->assertSame(
            "a: null\nb: null\nc: null",
            Toon::encode(['a' => NAN, 'b' => INF, 'c' => -INF])
        );
        $this->assertSame('null', Toon::encode(NAN));
        $this->assertSame('vals[3]: null,null,1', Toon::encode(['vals' => [INF, NAN, 1]]));
    }

    public function testNegativeZeroBecomesZero(): void
    {
        $this->assertSame('z: 0', Toon::encode(['z' => -0.0]));
        $this->assertSame('0', Toon::encode(-0.0));
    }

    public function testEmptyPhpArrayIsEmptyArray(): void
    {
        $this->assertSame('[]', Toon::encode([]));
        $this->assertSame('items: []', Toon::encode(['items' => []]));
    }

    public function testEmptyStdClassIsEmptyObject(): void
    {
        $this->assertSame('', Toon::encode(new \stdClass()));
        $this->assertSame('user:', Toon::encode(['user' => new \stdClass()]));
    }

    public function testAssociativeArrayBecomesObject(): void
    {
        $this->assertSame("b: 1\na: 2", Toon::encode(['b' => 1, 'a' => 2]));

        // Non-sequential integer keys are not a list: keys become strings
        // and need quoting because they are numeric-like.
        $this->assertSame("\"1\": a\n\"3\": b", Toon::encode([1 => 'a', 3 => 'b']));
    }

    public function testPlainObjectUsesPublicPropertiesOnly(): void
    {
        $this->assertSame("id: 1\nname: Ada", Toon::encode(new EncoderTestPlainObject()));
    }

    public function testClosureAndResourceBecomeNull(): void
    {
        $this->assertSame('fn: null', Toon::encode(['fn' => static fn (): int => 1]));

        $resource = fopen('php://memory', 'rb');
        try {
            $this->assertSame('res: null', Toon::encode(['res' => $resource]));
        } finally {
            fclose($resource);
        }
    }

    public function testBigIntegers(): void
    {
        $this->assertSame('n: 9223372036854775807', Toon::encode(['n' => PHP_INT_MAX]));
        $this->assertSame('n: -9223372036854775808', Toon::encode(['n' => PHP_INT_MIN]));

        // Big integral floats: plain integer string, never scientific below 1e21.
        $this->assertSame('n: 100000000000000000000', Toon::encode(['n' => 1e20]));
        $this->assertSame('n: 12345678901234567000', Toon::encode(['n' => 12345678901234567890.0]));

        // At and above 1e21: JS-style exponent with explicit '+'.
        $this->assertSame('n: 1e+21', Toon::encode(['n' => 1e21]));
        $this->assertSame('n: -1.5e+22', Toon::encode(['n' => -1.5e22]));

        // Tiny magnitudes use a lowercase negative exponent.
        $this->assertSame('n: 1e-7', Toon::encode(['n' => 1e-7]));
        $this->assertSame('n: 1.5e-7', Toon::encode(['n' => 1.5e-7]));
        $this->assertSame('n: 0.000001', Toon::encode(['n' => 1e-6]));
    }

    public function testCommaDelimiter(): void
    {
        $options = new EncodeOptions(delimiter: Delimiter::COMMA);

        $this->assertSame('tags[2]: x,y', Toon::encode(['tags' => ['x', 'y']], $options));
        $this->assertSame('tags[2]: "x,y",z', Toon::encode(['tags' => ['x,y', 'z']], $options));
    }

    public function testTabDelimiter(): void
    {
        $options = new EncodeOptions(delimiter: Delimiter::TAB);

        $this->assertSame("tags[2\t]: x\ty", Toon::encode(['tags' => ['x', 'y']], $options));

        // Commas are not the active delimiter, so they stay unquoted.
        $rows = ['rows' => [['a' => 1, 'b' => 'p,q'], ['a' => 2, 'b' => 'r']]];
        $this->assertSame(
            "rows[2\t]{a\tb}:\n  1\tp,q\n  2\tr",
            Toon::encode($rows, $options)
        );

        // Values containing a literal tab are quoted and escaped.
        $this->assertSame(
            "tags[2\t]: \"x\\ty\"\tz",
            Toon::encode(['tags' => ["x\ty", 'z']], $options)
        );
    }

    public function testPipeDelimiter(): void
    {
        $options = new EncodeOptions(delimiter: Delimiter::PIPE);

        $this->assertSame('tags[2|]: x|y', Toon::encode(['tags' => ['x', 'y']], $options));
        $this->assertSame('tags[2|]: "x|y"|z', Toon::encode(['tags' => ['x|y', 'z']], $options));

        // Object field values quote against the document delimiter (pipe here),
        // so commas remain unquoted.
        $this->assertSame('note: a,b', Toon::encode(['note' => 'a,b'], $options));
        $this->assertSame('note: "a|b"', Toon::encode(['note' => 'a|b'], $options));
    }

    public function testKeyFoldingSafeWithUnboundedDepth(): void
    {
        $options = new EncodeOptions(keyFolding: KeyFolding::SAFE, flattenDepth: null);

        $this->assertSame(
            'a.b.c: 1',
            Toon::encode(['a' => ['b' => ['c' => 1]]], $options)
        );
        $this->assertSame(
            'data.meta.items[2]: x,y',
            Toon::encode(['data' => ['meta' => ['items' => ['x', 'y']]]], $options)
        );
        $this->assertSame(
            'a.b.c:',
            Toon::encode(['a' => ['b' => ['c' => new \stdClass()]]], $options)
        );
    }

    public function testKeyFoldingSafeWithFlattenDepthTwo(): void
    {
        $options = new EncodeOptions(keyFolding: KeyFolding::SAFE, flattenDepth: 2);

        $this->assertSame(
            "a.b:\n  c:\n    d: 1",
            Toon::encode(['a' => ['b' => ['c' => ['d' => 1]]]], $options)
        );
        $this->assertSame(
            'a.b: 1',
            Toon::encode(['a' => ['b' => 1]], $options)
        );
    }

    public function testKeyFoldingSkipsCollisionsAndNonIdentifierSegments(): void
    {
        $options = new EncodeOptions(keyFolding: KeyFolding::SAFE);

        $this->assertSame(
            "a:\n  b: 1\na.b: literal",
            Toon::encode(['a' => ['b' => 1], 'a.b' => 'literal'], $options)
        );
        $this->assertSame(
            "data:\n  \"full-name\":\n    x: 1",
            Toon::encode(['data' => ['full-name' => ['x' => 1]]], $options)
        );
    }

    public function testDeeplyNestedMixedStructure(): void
    {
        $input = [
            'site' => [
                'name' => 'Example',
                'tags' => ['a', 'b'],
                'owner' => ['name' => 'Ada', 'meta' => new \stdClass()],
                'pages' => [
                    ['title' => 'Home', 'views' => 10],
                    ['title' => 'About', 'views' => 20],
                ],
                'mixed' => [1, ['x' => true], [1, 2], [], new \stdClass()],
            ],
        ];

        $expected = implode("\n", [
            'site:',
            '  name: Example',
            '  tags[2]: a,b',
            '  owner:',
            '    name: Ada',
            '    meta:',
            '  pages[2]{title,views}:',
            '    Home,10',
            '    About,20',
            '  mixed[5]:',
            '    - 1',
            '    - x: true',
            '    - [2]: 1,2',
            '    - [0]:',
            '    -',
        ]);

        $this->assertSame($expected, Toon::encode($input));
    }

    public function testListItemObjectWithTabularFirstField(): void
    {
        $input = [
            'items' => [
                [
                    'users' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob']],
                    'status' => 'active',
                ],
            ],
        ];

        $this->assertSame(
            "items[1]:\n  - users[2]{id,name}:\n      1,Ada\n      2,Bob\n    status: active",
            Toon::encode($input)
        );
    }

    /**
     * SPEC 7.2: strings with leading or trailing whitespace MUST be quoted.
     * "Whitespace" is the Unicode set the reference JS trim() strips, not
     * just ASCII; otherwise Unicode-trimming decoders silently strip data.
     */
    public function testLeadingOrTrailingUnicodeWhitespaceIsQuoted(): void
    {
        $whitespace = [
            'U+00A0 NBSP' => "\u{00A0}",
            'U+1680 ogham' => "\u{1680}",
            'U+2003 em space' => "\u{2003}",
            'U+200A hair space' => "\u{200A}",
            'U+2028 line sep' => "\u{2028}",
            'U+2029 para sep' => "\u{2029}",
            'U+202F narrow nbsp' => "\u{202F}",
            'U+205F math space' => "\u{205F}",
            'U+3000 ideographic' => "\u{3000}",
            'U+FEFF BOM' => "\u{FEFF}",
        ];

        foreach ($whitespace as $label => $char) {
            $this->assertSame(
                'k: "' . $char . 'x"',
                Toon::encode(['k' => $char . 'x']),
                "leading {$label} must force quoting"
            );
            $this->assertSame(
                'k: "x' . $char . '"',
                Toon::encode(['k' => 'x' . $char]),
                "trailing {$label} must force quoting"
            );
        }

        // Inline array values and tabular cells go through the same rule.
        $this->assertSame(
            "tags[2]: \"\u{00A0}a\",b",
            Toon::encode(['tags' => ["\u{00A0}a", 'b']])
        );
        $this->assertSame(
            "rows[1]{v}:\n  \"a\u{3000}\"",
            Toon::encode(['rows' => [['v' => "a\u{3000}"]]])
        );

        // Interior Unicode whitespace alone does not force quoting.
        $this->assertSame("k: a\u{00A0}b", Toon::encode(['k' => "a\u{00A0}b"]));
    }

    public function testInvalidUtf8StringValueThrows(): void
    {
        $this->expectException(EncodeException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        Toon::encode(['k' => "\xB1\x31"]);
    }

    public function testInvalidUtf8NestedStringValueThrows(): void
    {
        $this->expectException(EncodeException::class);

        Toon::encode(['items' => [['name' => "ok"], ['name' => "\xC3\x28"]]]);
    }

    public function testInvalidUtf8RootStringThrows(): void
    {
        $this->expectException(EncodeException::class);

        Toon::encode("\xB1\x31");
    }

    public function testInvalidUtf8KeyThrows(): void
    {
        $this->expectException(EncodeException::class);
        $this->expectExceptionMessage('Malformed UTF-8');

        Toon::encode(["\xB1\x31" => 1]);
    }

    public function testValidUtf8AndEmbeddedNulStillEncode(): void
    {
        // Multi-byte UTF-8 passes validation untouched.
        $this->assertSame('k: héllo 你好 🚀', Toon::encode(['k' => 'héllo 你好 🚀']));

        // NUL is valid UTF-8; it is a control char, so it is quoted/escaped.
        $this->assertSame('k: "a\u0000b"', Toon::encode(['k' => "a\0b"]));
    }

    public function testIndentFour(): void
    {
        $options = new EncodeOptions(indent: 4);

        $this->assertSame(
            "user:\n    name: Ada\n    role: admin",
            Toon::encode(['user' => ['name' => 'Ada', 'role' => 'admin']], $options)
        );

        $this->assertSame(
            "items[2]{id,ok}:\n    1,true\n    2,false",
            Toon::encode(['items' => [['id' => 1, 'ok' => true], ['id' => 2, 'ok' => false]]], $options)
        );
    }
}
