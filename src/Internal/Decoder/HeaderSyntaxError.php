<?php

declare(strict_types=1);

namespace Toon\Internal\Decoder;

/**
 * Internal signal that a candidate array header is malformed (SPEC §6).
 * In strict mode the caller converts it into a DecodeException; in non-strict
 * mode the caller may fall back to key-value parsing.
 *
 * @internal
 */
final class HeaderSyntaxError extends \RuntimeException
{
}
