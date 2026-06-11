<?php

declare(strict_types=1);

namespace Toon;

/**
 * Encoder options (SPEC §13).
 */
final class EncodeOptions
{
    /**
     * @param int $indent Number of spaces per indentation level (default 2).
     * @param string $delimiter Document delimiter: Delimiter::COMMA (default), Delimiter::TAB or Delimiter::PIPE.
     * @param string $keyFolding KeyFolding::OFF (default) or KeyFolding::SAFE.
     * @param int|null $flattenDepth Maximum number of segments to fold when keyFolding is "safe".
     *                               Null means unbounded (Infinity). Values less than 2 have no practical effect.
     */
    public function __construct(
        public readonly int $indent = 2,
        public readonly string $delimiter = Delimiter::COMMA,
        public readonly string $keyFolding = KeyFolding::OFF,
        public readonly ?int $flattenDepth = null,
    ) {
        if ($this->indent < 1) {
            throw new \InvalidArgumentException('indent must be >= 1');
        }
        if (!Delimiter::isValid($this->delimiter)) {
            throw new \InvalidArgumentException('delimiter must be one of ",", "\t" or "|"');
        }
        if (!in_array($this->keyFolding, [KeyFolding::OFF, KeyFolding::SAFE], true)) {
            throw new \InvalidArgumentException('keyFolding must be "off" or "safe"');
        }
        if ($this->flattenDepth !== null && $this->flattenDepth < 0) {
            throw new \InvalidArgumentException('flattenDepth must be a non-negative integer or null');
        }
    }
}
