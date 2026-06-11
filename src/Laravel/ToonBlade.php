<?php

declare(strict_types=1);

namespace Toon\Laravel;

/**
 * Blade directive compilers for TOON.
 *
 * Pure, framework-free functions that turn a directive's expression into the
 * PHP source Blade caches in the compiled view. Kept separate from the
 * ServiceProvider so the compilation logic is unit-testable without booting
 * Laravel. {@see ToonServiceProvider} wires these into the Blade compiler.
 *
 * Provided directives:
 *
 * - `@toon($value)` — encode any value in one shot. The array key supplies the
 *   table name, e.g. `@toon(['orders' => $orders])` emits `orders[N]{...}:`.
 *
 * - `@tooneach([name,] $items as $item) ... @toonrow([...]) ... @endtooneach` —
 *   loop-style projection. Each `@toonrow` pushes one associative row; the block
 *   collects them into a uniform array and encodes it. The optional leading
 *   string literal names the table (recommended — it gives the model context).
 *
 * Output is NOT HTML-escaped: TOON is built for LLM prompts, not page markup.
 * Wrap a call in `{{ }}` yourself if you are rendering into visible HTML.
 */
final class ToonBlade
{
    /** Matches an optional leading string literal + comma: `'orders', $items as $item`. */
    private const NAME_PREFIX_RE = '/^\s*((?:\'[^\']*\')|(?:"[^"]*"))\s*,\s*(.+)$/s';

    private function __construct()
    {
    }

    /**
     * Compile `@toon($expression)` to an encode-and-echo statement.
     */
    public static function compileToon(string $expression): string
    {
        return "<?php echo \\Toon\\Toon::encode({$expression}); ?>";
    }

    /**
     * Compile the opening `@tooneach(...)` directive.
     *
     * Accepts `$items as $item` or `'name', $items as $item`. Output is buffered
     * and discarded so literal whitespace inside the block (indentation, newlines
     * between `@toonrow` calls) never leaks into the encoded result — only the
     * final table, emitted by {@see compileEndtooneach()}, is echoed.
     */
    public static function compileTooneach(string $expression): string
    {
        if (preg_match(self::NAME_PREFIX_RE, $expression, $matches) === 1) {
            $key = $matches[1];
            $loop = $matches[2];
        } else {
            $key = 'null';
            $loop = trim($expression);
        }

        return "<?php \$__toonKey = {$key}; \$__toonRows = []; ob_start(); foreach ({$loop}): ?>";
    }

    /**
     * Compile `@toonrow($expression)` — push one row onto the current block.
     *
     * The expression should evaluate to an associative array (the row's fields).
     */
    public static function compileToonrow(string $expression): string
    {
        return "<?php \$__toonRows[] = {$expression}; ?>";
    }

    /**
     * Compile `@endtooneach` — close the loop and encode the collected rows.
     *
     * Blade always invokes a directive handler with the (here empty) expression
     * string, so the unused parameter is accepted and ignored.
     */
    public static function compileEndtooneach(string $expression = ''): string
    {
        return "<?php endforeach; ob_end_clean();"
            . " echo \\Toon\\Toon::encode(\$__toonKey !== null ? [\$__toonKey => \$__toonRows] : \$__toonRows); ?>";
    }
}
