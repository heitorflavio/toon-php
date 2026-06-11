<?php

declare(strict_types=1);

namespace Toon;

/**
 * Key folding modes for the encoder (SPEC §13.4).
 */
final class KeyFolding
{
    public const OFF = 'off';
    public const SAFE = 'safe';

    private function __construct()
    {
    }
}
