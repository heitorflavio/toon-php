<?php

declare(strict_types=1);

namespace Toon;

/**
 * Decoder options (SPEC §13).
 */
final class DecodeOptions
{
    /**
     * @param int $indent Number of spaces per indentation level (default 2).
     * @param bool $strict Enforce strict-mode validation (SPEC §14). Default true.
     * @param string $expandPaths ExpandPaths::OFF (default) or ExpandPaths::SAFE.
     * @param bool $associative When true, decoded objects are returned as associative arrays
     *                          (like json_decode($s, true)). When false (default), objects are
     *                          returned as stdClass, which preserves the distinction between
     *                          empty objects ({}) and empty arrays ([]).
     */
    public function __construct(
        public readonly int $indent = 2,
        public readonly bool $strict = true,
        public readonly string $expandPaths = ExpandPaths::OFF,
        public readonly bool $associative = false,
    ) {
        if ($this->indent < 1) {
            throw new \InvalidArgumentException('indent must be >= 1');
        }
        if (!in_array($this->expandPaths, [ExpandPaths::OFF, ExpandPaths::SAFE], true)) {
            throw new \InvalidArgumentException('expandPaths must be "off" or "safe"');
        }
    }
}
