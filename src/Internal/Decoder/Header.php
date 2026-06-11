<?php

declare(strict_types=1);

namespace Toon\Internal\Decoder;

/**
 * Parsed array header (SPEC §6): declared length, active delimiter, optional
 * tabular field list, and any inline content found after the colon.
 *
 * @internal
 */
final class Header
{
    /**
     * @param list<array{string, bool}>|null $fields Field names as [name, wasQuoted] pairs, or null when no fields segment is present.
     */
    public function __construct(
        public readonly int $length,
        public readonly string $delimiter,
        public readonly ?array $fields,
        public readonly string $rest,
    ) {
    }
}
