<?php

declare(strict_types=1);

namespace Toon\Internal\Decoder;

/**
 * A single non-blank input line with its 1-based line number, computed depth
 * (SPEC §12) and indentation-stripped content.
 *
 * @internal
 */
final class Line
{
    public function __construct(
        public readonly int $no,
        public readonly int $depth,
        public readonly string $content,
    ) {
    }
}
