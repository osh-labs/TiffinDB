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
 * autoloader, a configuration file (config/app.php, or the optional
 * config/local.php) that is missing, invalid, throws, does not parse, or
 * exhausts memory (an uncatchable fatal error, handled by the shutdown
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
            foreach (['public/index.php', 'config/app.php', 'config/local.php'] as $file) {
                if (is_file($this->root . '/' . $file)) {
                    unlink($this->root . '/' . $file);
                }
            }
            foreach (['src', 'vendor', 'templates', 'strings'] as $link) {
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

    private function project(?string $config, bool $vendor = true, ?string $local = null): string
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
        if ($local !== null) {
            // A complete application, so that only config/local.php decides.
            file_put_contents($this->root . '/config/local.php', $local);
            symlink($repo . '/templates', $this->root . '/templates');
            symlink($repo . '/strings', $this->root . '/strings');
        }

        return $this->root;
    }

    /** @return array{0: string, 1: int} Standard output and exit code. */
    private static function runFrontController(string $root, string $method = 'GET'): array
    {
        $code = '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . '; $_SERVER["REQUEST_URI"] = "/";'
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

    public function testFatalErrorOnHeadSendsNoBody(): void
    {
        $root = $this->project(self::HEADER . "ini_set('memory_limit', '16M');\n\$a = str_repeat('x', 64 * 1024 * 1024);\nreturn [];\n");
        [$stdout] = self::runFrontController($root, 'HEAD');

        self::assertSame('', $stdout);
    }

    public function testCaughtFailureOnHeadSendsNoBody(): void
    {
        $root = $this->project(self::HEADER . "throw new RuntimeException('config failure');\n");
        [$stdout] = self::runFrontController($root, 'HEAD');

        self::assertSame('', $stdout);
    }

    private const GOOD_CONFIG = self::HEADER
        . "return ['version' => '1.0.0', 'source_url' => 'https://example.com/source', 'appearance_preview' => false];\n";

    /** @return array<string, array{string}> */
    public static function brokenLocalConfigs(): array
    {
        return [
            'not an array' => [self::HEADER . "return 'x';\n"],
            'unknown key' => [self::HEADER . "return ['version' => '2'];\n"],
            'secret-looking key' => [self::HEADER . "return ['password' => 'x'];\n"],
            'relative data_dir' => [self::HEADER . "return ['data_dir' => 'data'];\n"],
            'data_dir not a string' => [self::HEADER . "return ['data_dir' => 1];\n"],
            'empty data_dir' => [self::HEADER . "return ['data_dir' => ''];\n"],
            'unknown time zone' => [self::HEADER . "return ['timezone' => 'Nowhere/Example'];\n"],
            'time zone not a string' => [self::HEADER . "return ['timezone' => 3];\n"],
            'secure_cookies not a bool' => [self::HEADER . "return ['secure_cookies' => 'yes'];\n"],
            'throws' => [self::HEADER . "throw new RuntimeException('local failure');\n"],
        ];
    }

    public function testValidLocalConfigurationIsAccepted(): void
    {
        // The positive control for the cases below: `/` redirects to setup
        // (an empty body) because the configured folder holds no data.
        $missing = sys_get_temp_dir() . '/tiffindb-no-data-' . bin2hex(random_bytes(6));
        $local = self::HEADER . 'return ' . var_export(
            ['data_dir' => $missing, 'timezone' => 'Europe/Berlin', 'secure_cookies' => true],
            true,
        ) . ";\n";
        $root = $this->project(self::GOOD_CONFIG, true, $local);
        [$stdout] = self::runFrontController($root);

        self::assertSame('', $stdout);
        self::assertDirectoryDoesNotExist($missing);
    }

    #[DataProvider('brokenLocalConfigs')]
    public function testBrokenLocalConfigurationGivesTheFallback(string $local): void
    {
        $root = $this->project(self::GOOD_CONFIG, true, $local);
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
