<?php

declare(strict_types=1);

namespace Toon;

/**
 * Supported TOON delimiters (SPEC §11).
 */
final class Delimiter
{
    public const COMMA = ',';
    public const TAB = "\t";
    public const PIPE = '|';

    /** @var list<string> */
    public const ALL = [self::COMMA, self::TAB, self::PIPE];

    private function __construct()
    {
    }

    public static function isValid(string $delimiter): bool
    {
        return in_array($delimiter, self::ALL, true);
    }
}
