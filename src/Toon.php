<?php

declare(strict_types=1);

namespace Toon;

/**
 * TOON - Token-Oriented Object Notation.
 *
 * Static facade for encoding PHP values to TOON and decoding TOON documents
 * back to PHP values. Implements TOON SPEC v3.3 (https://github.com/toon-format/spec).
 *
 * ```php
 * $toon = Toon::encode(['users' => [
 *     ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
 *     ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
 * ]]);
 * // users[2]{id,name,role}:
 * //   1,Alice,admin
 * //   2,Bob,user
 *
 * $data = Toon::decode($toon);
 * ```
 */
final class Toon
{
    public const VERSION = '0.1.0';
    public const SPEC_VERSION = '3.3';

    private function __construct()
    {
    }

    /**
     * Encode a PHP value to a TOON string.
     *
     * Host-type normalization (SPEC §3): lists become arrays, associative arrays
     * and stdClass become objects, JsonSerializable values are serialized first,
     * DateTimeInterface becomes an ISO 8601 string, backed enums use their value,
     * NAN and INF become null.
     */
    public static function encode(mixed $value, ?EncodeOptions $options = null): string
    {
        return (new ToonEncoder($options ?? new EncodeOptions()))->encode($value);
    }

    /**
     * Decode a TOON string to a PHP value.
     *
     * @throws Exception\DecodeException on syntax or strict-mode violations (SPEC §14).
     */
    public static function decode(string $toon, ?DecodeOptions $options = null): mixed
    {
        return (new ToonDecoder($options ?? new DecodeOptions()))->decode($toon);
    }
}
