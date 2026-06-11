<?php

declare(strict_types=1);

namespace Toon\Internal\Decoder;

/**
 * Mutable ordered map used internally to represent TOON objects during
 * parsing and path expansion (SPEC §13.4). Using a dedicated node type keeps
 * the {} vs [] distinction intact and remembers which keys were quoted in the
 * source document (quoted keys are never eligible for path expansion).
 *
 * Note: PHP canonicalizes numeric-string array keys ("123" becomes int 123);
 * keys are cast back to string when the node is converted to its public form.
 *
 * @internal
 */
final class ObjNode
{
    /** @var array<string|int, mixed> */
    public array $entries = [];

    /** @var array<string|int, bool> */
    public array $quotedKeys = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function set(string $key, mixed $value, bool $quoted = false): void
    {
        $this->entries[$key] = $value;
        $this->quotedKeys[$key] = $quoted;
    }

    public function isQuoted(string|int $key): bool
    {
        return $this->quotedKeys[$key] ?? false;
    }
}
