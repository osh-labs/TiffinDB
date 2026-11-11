<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Web\Fallback;

/**
 * public/index.php when the bootstrap fails outside the kernel: a missing
 * autoloader, a configuration file that is missing, throws, does not parse,
 * or exhausts memory (an uncatchable fatal error, handled by the shutdown
 * function). Each case runs the real front controller in a child process,
 * offline and with no server, inside a temporary project root whose
 * `public/index.php` is a copy and whose `src/` and `vendor/` are links to
 * the real ones. Only the body is observable from a command-line run; the
 * fallback's headers are covered in process by ResponseTest.
 */
#[CoversNothing]
final class FrontControllerFailureTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach (['public/index.php', 'config/app.php'] as $file) {
                if (is_file($this->root . '/' . $file)) {
                    unlink($this->root . '/' . $file);
                }
            }
            foreach (['src', 'vendor'] as $link) {
                if (is_link($this->root . '/' . $link)) {
                    unlink($this->root . '/' . $link);
                }
            }
            foreach (['public', 'config', ''] as $dir) {
                if (is_dir($this->root . '/' . $dir)) {
                    rmdir($this->root . '/' . $dir);
                }
            }
        }
    }

    private function project(?string $config, bool $vendor = true): string
    {
        $repo = dirname(__DIR__, 2);
        $this->root = sys_get_temp_dir() . '/tiffindb-fc-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/public', 0700, true) || !mkdir($this->root . '/config', 0700)) {
            throw new RuntimeException('Cannot create the temporary project.');
        }
        copy($repo . '/public/index.php', $this->root . '/public/index.php');
        symlink($repo . '/src', $this->root . '/src');
        if ($vendor) {
            symlink($repo . '/vendor', $this->root . '/vendor');
        }
        if ($config !== null) {
            file_put_contents($this->root . '/config/app.php', $config);
        }

        return $this->root;
    }

    /** @return array{0: string, 1: int} Standard output and exit code. */
    private static function runFrontController(string $root): array
    {
        $code = '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/";'
            . ' $_SERVER["SCRIPT_NAME"] = "/index.php"; require ' . var_export($root . '/public/index.php', true) . ';';
        $process = proc_open(
            [PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=/dev/null', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, proc_close($process)];
    }

    private const HEADER = "<?php\ndeclare(strict_types=1);\n";

    /** @return array<string, array{string|null}> */
    public static function brokenConfigs(): array
    {
        return [
            'missing' => [null],
            'throws' => [self::HEADER . "throw new RuntimeException('config failure at /secret/path');\n"],
            'does not parse' => [self::HEADER . "return [ 'version' => ;\n"],
            'calls an undefined function' => [self::HEADER . "return no_such_function();\n"],
            'exhausts memory' => [self::HEADER . "ini_set('memory_limit', '16M');\n\$a = str_repeat('x', 64 * 1024 * 1024);\nreturn [];\n"],
            'not an array' => [self::HEADER . "return 'nonsense';\n"],
        ];
    }

    #[DataProvider('brokenConfigs')]
    public function testBrokenConfigurationGivesTheFallback(?string $config): void
    {
        $root = $this->project($config);
        [$stdout] = self::runFrontController($root);

        self::assertSame(Fallback::BODY, $stdout);
    }

    public function testMissingDependenciesGiveTheDependenciesMessage(): void
    {
        $root = $this->project(self::HEADER . "return [];\n", false);
        [$stdout] = self::runFrontController($root);

        self::assertSame(Fallback::DEPENDENCIES_BODY, $stdout);
    }

    public function testFallbackBodiesRevealNothing(): void
    {
        foreach ([Fallback::BODY, Fallback::DEPENDENCIES_BODY] as $body) {
            self::assertStringNotContainsString('/', $body);
            self::assertStringNotContainsString('PHP', $body);
            self::assertStringNotContainsString(PHP_VERSION, $body);
        }
    }
}
