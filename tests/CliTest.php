<?php

declare(strict_types=1);

namespace Toon\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the bin/toon CLI.
 *
 * Each test spawns the CLI with proc_open() using an argv array (no shell),
 * passing input through the STDIN pipe — fully OS-portable, no redirection.
 *
 * Exit-code contract: 0 success, 1 usage error, 2 encode/decode/IO error.
 */
final class CliTest extends TestCase
{
    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private static function runCli(array $args, string $stdin = ''): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/toon'], $args);
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'failed to spawn the CLI process');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    public function testEncodeHappyPath(): void
    {
        $result = self::runCli(['encode'], '{"a":1,"tags":["x","y"]}');

        self::assertSame(0, $result['exit'], 'stderr: ' . $result['stderr']);
        self::assertSame("a: 1\ntags[2]: x,y\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testDecodeHappyPathCompact(): void
    {
        $result = self::runCli(
            ['decode', '--compact'],
            "users[2]{id,name}:\n  1,Alice\n  2,Bob\n",
        );

        self::assertSame(0, $result['exit'], 'stderr: ' . $result['stderr']);
        self::assertSame('{"users":[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]}' . "\n", $result['stdout']);
    }

    public function testDecodeDefaultIsPrettyPrintedAndPreservesEmptyObject(): void
    {
        $result = self::runCli(['decode'], "empty:\nn: 1\n");

        self::assertSame(0, $result['exit'], 'stderr: ' . $result['stderr']);
        // Pretty output spans multiple lines and keeps {} distinct from [].
        self::assertStringContainsString('"empty": {}', $result['stdout']);
        self::assertStringContainsString('"n": 1', $result['stdout']);
        self::assertGreaterThan(2, substr_count($result['stdout'], "\n"));
    }

    public function testRoundTrip(): void
    {
        $json = '{"id":7,"name":"Ana","empty":{},"list":[],"nested":{"ok":true,"pi":3.14},"items":[{"a":1,"b":"x"},{"a":2,"b":"y"}]}';

        $encoded = self::runCli(['encode'], $json);
        self::assertSame(0, $encoded['exit'], 'stderr: ' . $encoded['stderr']);

        $decoded = self::runCli(['decode', '--compact'], $encoded['stdout']);
        self::assertSame(0, $decoded['exit'], 'stderr: ' . $decoded['stderr']);
        self::assertSame($json . "\n", $decoded['stdout']);
    }

    public function testInvalidJsonInputExitsWithCode2(): void
    {
        $result = self::runCli(['encode'], '{"a":');

        self::assertSame(2, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('invalid JSON input', $result['stderr']);
    }

    public function testInvalidToonInputExitsWithCode2(): void
    {
        $result = self::runCli(['decode'], "items[2]: a,b,c\n");

        self::assertSame(2, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('decode error', $result['stderr']);
    }

    public function testUnknownFlagExitsWithCode1AndPrintsUsage(): void
    {
        $result = self::runCli(['encode', '--bogus'], '{}');

        self::assertSame(1, $result['exit']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('unknown option "--bogus"', $result['stderr']);
        self::assertStringContainsString('Usage:', $result['stderr']);
    }

    public function testMissingSubcommandExitsWithCode1(): void
    {
        $result = self::runCli([]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('missing subcommand', $result['stderr']);
    }

    public function testDelimiterTab(): void
    {
        $result = self::runCli(
            ['encode', '--delimiter=tab'],
            '{"rows":[{"id":1,"name":"A,B"},{"id":2,"name":"C"}]}',
        );

        self::assertSame(0, $result['exit'], 'stderr: ' . $result['stderr']);
        self::assertSame("rows[2\t]{id\tname}:\n  1\tA,B\n  2\tC\n", $result['stdout']);
    }
}
