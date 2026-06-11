<?php

declare(strict_types=1);

namespace Toon\Exception;

/**
 * Thrown when a TOON document cannot be decoded (syntax or strict-mode violations, SPEC §14).
 */
class DecodeException extends ToonException
{
    public function __construct(
        string $message,
        public readonly ?int $lineNumber = null,
    ) {
        parent::__construct($lineNumber !== null ? sprintf('%s (line %d)', $message, $lineNumber) : $message);
    }
}
