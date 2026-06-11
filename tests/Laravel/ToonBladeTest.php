<?php

declare(strict_types=1);

namespace Toon\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Toon\Laravel\ToonBlade;

/**
 * Tests for the Blade directive compilers.
 *
 * Two layers: the compiled PHP source is asserted directly, and that same
 * source is executed through a tiny render harness (include + output buffer,
 * mirroring how Blade runs a cached view) to prove it emits valid TOON. No
 * Laravel framework is booted.
 */
final class ToonBladeTest extends TestCase
{
    /**
     * Execute compiled directive source the way Blade runs a cached view.
     *
     * @param array<string, mixed> $scope variables made available to the template
     */
    private static function render(string $compiled, array $scope): string
    {
        $file = tempnam(sys_get_temp_dir(), 'toonblade') . '.php';
        file_put_contents($file, $compiled);

        $run = static function () use ($file, $scope): string {
            extract($scope, EXTR_SKIP);
            ob_start();
            include $file;

            return (string) ob_get_clean();
        };

        try {
            return $run();
        } finally {
            @unlink($file);
        }
    }

    public function testCompileToonEmitsEncodeCall(): void
    {
        self::assertSame(
            '<?php echo \\Toon\\Toon::encode($data); ?>',
            ToonBlade::compileToon('$data'),
        );
    }

    public function testToonDirectiveEncodesNamedTable(): void
    {
        $output = self::render(
            ToonBlade::compileToon('$data'),
            ['data' => ['users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ]]],
        );

        self::assertSame(
            "users[2]{id,name}:\n  1,Alice\n  2,Bob",
            $output,
        );
    }

    public function testTooneachParsesOptionalNamePrefix(): void
    {
        self::assertSame(
            '<?php $__toonKey = \'orders\'; $__toonRows = []; ob_start(); foreach ($orders as $o): ?>',
            ToonBlade::compileTooneach("'orders', \$orders as \$o"),
        );
    }

    public function testTooneachWithoutNameUsesNullKey(): void
    {
        self::assertSame(
            '<?php $__toonKey = null; $__toonRows = []; ob_start(); foreach ($orders as $o): ?>',
            ToonBlade::compileTooneach('$orders as $o'),
        );
    }

    public function testTooneachBlockEncodesNamedProjection(): void
    {
        $compiled = ToonBlade::compileTooneach("'orders', \$orders as \$o")
            // literal whitespace between directives must NOT leak into the output:
            . "\n    "
            . ToonBlade::compileToonrow("['sku' => \$o['sku'], 'qty' => \$o['qty']]")
            . "\n"
            . ToonBlade::compileEndtooneach();

        $output = self::render($compiled, ['orders' => [
            ['sku' => 'A1', 'qty' => 2, 'price' => 9.99],
            ['sku' => 'B2', 'qty' => 1, 'price' => 14.5],
        ]]);

        self::assertSame(
            "orders[2]{sku,qty}:\n  A1,2\n  B2,1",
            $output,
        );
    }

    public function testTooneachBlockWithoutNameEmitsRootTable(): void
    {
        $compiled = ToonBlade::compileTooneach('$orders as $o')
            . ToonBlade::compileToonrow("['sku' => \$o['sku'], 'qty' => \$o['qty']]")
            . ToonBlade::compileEndtooneach();

        $output = self::render($compiled, ['orders' => [
            ['sku' => 'A1', 'qty' => 2],
            ['sku' => 'B2', 'qty' => 1],
        ]]);

        self::assertSame(
            "[2]{sku,qty}:\n  A1,2\n  B2,1",
            $output,
        );
    }
}
