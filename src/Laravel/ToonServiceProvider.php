<?php

declare(strict_types=1);

namespace Toon\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Registers TOON's Blade directives.
 *
 * Auto-discovered by Laravel via `extra.laravel.providers` in composer.json —
 * installing the package is enough, no manual registration needed. This class
 * is only ever loaded inside a Laravel application; the library itself has no
 * runtime dependency on illuminate/*.
 *
 * @see ToonBlade for the directives and their syntax.
 */
final class ToonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blade::directive('toon', [ToonBlade::class, 'compileToon']);
        Blade::directive('tooneach', [ToonBlade::class, 'compileTooneach']);
        Blade::directive('toonrow', [ToonBlade::class, 'compileToonrow']);
        Blade::directive('endtooneach', [ToonBlade::class, 'compileEndtooneach']);
    }
}
