<?php

declare(strict_types=1);

namespace Toon;

/**
 * Path expansion modes for the decoder (SPEC §13.4).
 */
final class ExpandPaths
{
    public const OFF = 'off';
    public const SAFE = 'safe';

    private function __construct()
    {
    }
}
