<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-WEB-12: runs public/index.php itself in a child PHP process, offline
 * and with no server. Status and headers cannot be read from a
 * command-line run; T-WEB-3 and T-WEB-5 cover them in process.
 */
#[CoversNothing]
final class FrontControllerTest extends TestCase
{
    /**
     * Runs the front controller for one request.
     *
     * @return array{0: string, 1: string, 2: int} Standard output, standard error, exit code.
     */
    private static function runFrontController(string $uri, string $method = 'GET'): array
    {
        $code = sprintf(
            '$_SERVER["REQUEST_METHOD"] = %s; $_SERVER["REQUEST_URI"] = %s; $_SERVER["SCRIPT_NAME"] = "/index.php";'
            . ' $q = parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY); $_GET = [];'
            . ' if (is_string($q)) { parse_str($q, $_GET); }'
            . ' require %s;',
            var_export($method, true),
            var_export($uri, true),
            var_export(RepoFiles::path('public/index.php'), true),
        );

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_log=', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$stdout, $stderr, $exit];
    }

    private static function sourceUrl(): string
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        self::assertIsString($config['source_url']);

        return $config['source_url'];
    }

    private static function string(string $key): string
    {
        $strings = require RepoFiles::path('strings/en.php');
        self::assertIsArray($strings);

        return $strings[$key];
    }

    public function testStartPage(): void
    {
        [$out, $err, $exit] = self::runFrontController('/');

        self::assertSame('', $err);
        self::assertSame(0, $exit);
        self::assertStringStartsWith('<!DOCTYPE html>', $out);
        HtmlAssertions::assertShell($out, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'));
        HtmlAssertions::assertNoInlineCode($out, self::sourceUrl());
        self::assertStringContainsString('>' . self::string('shell.footer.source') . '</a>', $out);
        self::assertNull(HtmlAssertions::rootClass($out));
    }

    public function testNotFound(): void
    {
        [$out, $err] = self::runFrontController('/nope');

        self::assertSame('', $err);
        self::assertSame(self::string('error.404.title'), HtmlAssertions::text($out, '//h1'));
        HtmlAssertions::assertShell($out, self::sourceUrl(), self::string('shell.footer.licence'), self::string('shell.footer.source'));
    }

    public function testDarkTheme(): void
    {
        [$out, $err] = self::runFrontController('/?theme=dark');

        self::assertSame('', $err);
        self::assertSame('t-dark', HtmlAssertions::rootClass($out));
    }

    public function testHeadSendsNoBody(): void
    {
        [$out, $err] = self::runFrontController('/', 'HEAD');

        self::assertSame('', $err);
        self::assertSame('', $out);
    }
}
