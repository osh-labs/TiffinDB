<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Runs tools/host-check/host-check.php through the PHP CLI, and through the
 * PHP built-in server on a loopback port, in a temporary directory.
 * Offline: all traffic stays on 127.0.0.1.
 */
#[CoversNothing]
final class HostCheckTest extends TestCase
{
    private const TOKEN = 'ExampleToken0123456789';

    private const REQUIRED_KEYS = [
        'php.version', 'php.sapi', 'ext.pdo_sqlite', 'ext.zip', 'ext.xmlreader', 'ext.mbstring',
        'ext.fileinfo', 'ext.openssl', 'ext.session', 'crypto.random_bytes', 'crypto.argon2id',
        'sqlite.version', 'sqlite.fts5', 'sqlite.json1',
        'limit.memory_limit', 'limit.max_execution_time', 'limit.upload_max_filesize',
        'limit.post_max_size', 'limit.max_file_uploads', 'limit.max_input_vars',
        'php.disable_functions', 'fs.stale_leftovers', 'fs.outside_webroot_writable', 'fs.beside_script_writable',
        'fs.sqlite_file', 'fs.sqlite_journal_mode', 'fs.flock', 'fs.cleanup',
        'web.server', 'web.https', 'web.selftest',
    ];

    private string $workDir = '';

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $base = (string) realpath(sys_get_temp_dir());
        $this->workDir = $base . '/hostcheck-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir . '/public', 0700, true);
        $this->installScript('');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($this->workDir);
        }
    }

    private function installScript(string $token): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/host-check/host-check.php');
        $src = str_replace("const HOST_CHECK_TOKEN = '';", "const HOST_CHECK_TOKEN = '" . $token . "';", $src, $count);
        $this->assertSame(1, $count, 'token constant not found');
        file_put_contents($this->workDir . '/public/host-check.php', $src);
    }

    /**
     * @param list<string> $ini php -d settings, e.g. ['disable_functions=flock']
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function runCli(array $ini = [], array $args = []): array
    {
        $cmd = [PHP_BINARY];
        foreach ($ini as $setting) {
            $cmd[] = '-d';
            $cmd[] = $setting;
        }
        $cmd = array_merge($cmd, ['public/host-check.php'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->workDir);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    /** @return array<string,mixed> */
    private function extractJson(string $out): array
    {
        $begin = "----- BEGIN TIFFINDB HOST CHECK JSON -----\n";
        $end = "\n----- END TIFFINDB HOST CHECK JSON -----";
        $start = strpos($out, $begin);
        $stop = strpos($out, $end);
        $this->assertNotFalse($start, 'JSON begin marker missing');
        $this->assertNotFalse($stop, 'JSON end marker missing');
        $start += strlen($begin);
        $data = json_decode(substr($out, $start, $stop - $start), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        return $data;
    }

    /** @return list<string> paths relative to the work dir */
    private function filesInWorkDir(): array
    {
        $left = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $left[] = substr($item->getPathname(), strlen($this->workDir) + 1);
        }
        sort($left);
        return $left;
    }

    private function assertNoSensitiveText(string $out): void
    {
        $this->assertStringNotContainsString($this->workDir, $out);
        $this->assertStringNotContainsString(self::TOKEN, $out);
        $this->assertStringNotContainsString('127.0.0.1', $out);
        // Only assert on the host name when it is not ordinary report vocabulary,
        // i.e. it does not occur as a word anywhere in the script source.
        $host = (string) gethostname();
        $word = '/(?<![A-Za-z0-9_-])' . preg_quote($host, '/') . '(?![A-Za-z0-9_-])/i';
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/host-check/host-check.php');
        if (strlen($host) > 3 && preg_match($word, $source) !== 1) {
            $this->assertDoesNotMatchRegularExpression($word, $out);
        }
    }

    // ----- CLI -----------------------------------------------------------

    public function testCliReportIsCompleteSafeAndLeavesNoFiles(): void
    {
        [$code, $out, $err] = $this->runCli();
        $data = $this->extractJson($out);

        $this->assertSame('tiffindb-host-check', $data['tool']);
        $this->assertSame('cli', $data['mode']);
        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $data['checks'], "missing check $key");
            $this->assertContains($data['checks'][$key]['status'], ['PASS', 'WARN', 'FAIL', 'INFO']);
        }
        $this->assertSame($data['summary']['fail'] > 0 ? 1 : 0, $code);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
        $this->assertSame('', $err);
        $this->assertNoSensitiveText($out);
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }

    public function testHelpExitsZeroAndUnknownOptionExitsTwo(): void
    {
        [$code, $out] = $this->runCli([], ['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Exit code', $out);

        [$code] = $this->runCli([], ['--bogus']);
        $this->assertSame(2, $code);
    }

    public function testFailingCheckGivesExitCodeOne(): void
    {
        [$code, $out] = $this->runCli(['disable_functions=flock']);
        $data = $this->extractJson($out);
        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $data['checks']['php.disable_functions']['status']);
        $this->assertSame('FAIL', $data['checks']['fs.flock']['status']);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }

    public function testDisabledChmodStillReportsAndCleansUp(): void
    {
        [$code, $out, $err] = $this->runCli(['disable_functions=chmod']);
        $data = $this->extractJson($out);
        $this->assertSame('', $err);
        $this->assertSame($data['summary']['fail'] > 0 ? 1 : 0, $code);
        $this->assertContains('chmod', $data['checks']['php.disable_functions']['value']['optional']);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
        $this->assertNoSensitiveText($out);
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }

    public function testCleanupFailureIsReportedNotHidden(): void
    {
        [$code, $out, $err] = $this->runCli(['disable_functions=rmdir']);
        $data = $this->extractJson($out);
        $this->assertSame('', $err);
        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $data['checks']['fs.cleanup']['status']);
        $this->assertNotEmpty($data['checks']['fs.cleanup']['value']);
        $this->assertNoSensitiveText($out);
        // tearDown removes the leftovers the script could not.
    }

    public function testStaleLeftoversFromKilledRunAreRemoved(): void
    {
        $stale = $this->workDir . '/tiffindb-hostcheck-0123456789abcdef';
        mkdir($stale . '/deny', 0700, true);
        file_put_contents($stale . '/deny/probe.txt', "synthetic\n");
        touch($stale, time() - 3600);
        $recent = $this->workDir . '/public/tiffindb-hostcheck-fedcba9876543210';
        mkdir($recent, 0700);

        [, $out] = $this->runCli();
        $data = $this->extractJson($out);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($recent, 'recent directories must be left alone');
        $this->assertSame('WARN', $data['checks']['fs.stale_leftovers']['status']);
        $this->assertCount(1, $data['checks']['fs.stale_leftovers']['value']['removed']);
        $this->assertCount(1, $data['checks']['fs.stale_leftovers']['value']['kept']);
        $this->assertNoSensitiveText($out);
    }

    public function testDisabledIsLinkNeverFollowsPlantedSymlink(): void
    {
        $target = $this->workDir . '/keep';
        mkdir($target, 0700);
        file_put_contents($target . '/precious.txt', "synthetic\n");
        $link = $this->workDir . '/tiffindb-hostcheck-00112233aabbccdd';
        $reason = '';
        if (!self::quietly(static fn (): bool => symlink($target, $link), $reason)) {
            $this->markTestSkipped('cannot create symbolic links here' . ($reason !== '' ? ': ' . $reason : ''));
        }
        touch($target, time() - 3600);

        [$code, $out, $err] = $this->runCli(['disable_functions=is_link']);
        $data = $this->extractJson($out);
        $this->assertSame('', $err);
        $this->assertFileExists($target . '/precious.txt', 'symlink target contents were deleted');
        $this->assertTrue(is_link($link), 'planted symlink was removed');
        $this->assertSame('WARN', $data['checks']['fs.stale_leftovers']['status']);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
        $this->assertSame($data['summary']['fail'] > 0 ? 1 : 0, $code);
        $this->assertNoSensitiveText($out);
    }

    /**
     * Runs $call with a local error handler instead of the @ operator, so an
     * expected warning (no symlink support, no free port) is captured, not shown.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private static function quietly(callable $call, string &$warning = ''): mixed
    {
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    // ----- HTTP (built-in server on loopback) ------------------------------

    private function startServer(): int
    {
        $probe = self::quietly(static fn () => stream_socket_server('tcp://127.0.0.1:0'));
        if ($probe === false) {
            $this->markTestSkipped('cannot bind a loopback port');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = '4';
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->workDir . '/public'];
        $null = ['file', '/dev/null', 'w'];
        $this->server = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, $this->workDir, $env);
        if (!is_resource($this->server)) {
            $this->markTestSkipped('cannot start the PHP built-in server');
        }
        for ($i = 0; $i < 50; $i++) {
            $fp = self::quietly(static fn () => stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2));
            if ($fp !== false) {
                fclose($fp);
                return $port;
            }
            usleep(100000);
        }
        $this->stopServer();
        $this->markTestSkipped('PHP built-in server did not start');
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->server = null;
    }

    /** @return array{0:int,1:string} status, body */
    private function httpGet(int $port, string $path, string $host = ''): array
    {
        $fp = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
        $this->assertIsResource($fp);
        stream_set_timeout($fp, 60);
        fwrite($fp, "GET $path HTTP/1.0\r\nHost: " . ($host !== '' ? $host : '127.0.0.1:' . $port) . "\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($fp);
        fclose($fp);
        $this->assertSame(1, preg_match('#^HTTP/\S+ (\d{3})#', $raw, $m), 'no HTTP status line');
        $pos = strpos($raw, "\r\n\r\n");
        return [(int) $m[1], $pos === false ? '' : substr($raw, $pos + 4)];
    }

    public function testHttpGuardRefusesWithoutValidToken(): void
    {
        $cases = [
            ['', '?token=anything'],
            ['short1', '?token=short1'],
            ['not-letters-and-digits-only', '?token=not-letters-and-digits-only'],
            [self::TOKEN, ''],
            [self::TOKEN, '?token=WrongToken0123456789'],
            [self::TOKEN, '?token[]=' . self::TOKEN],
            [self::TOKEN, '?probe=auth&token=' . self::TOKEN],
        ];
        foreach ($cases as [$configured, $query]) {
            $this->installScript($configured);
            $port = $this->startServer();
            [$status, $body] = $this->httpGet($port, '/host-check.php' . $query);
            $this->stopServer();
            $this->assertSame(403, $status, "expected 403 for query '$query'");
            $this->assertStringNotContainsString('BEGIN TIFFINDB HOST CHECK JSON', $body);
            $this->assertStringNotContainsString(self::TOKEN, $body);
        }
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }

    public function testHttpReportWithHostileHostHeaderStaysLocalAndClean(): void
    {
        // A decoy listener: self-requests must never reach it, even though
        // the Host header names its port.
        $decoy = self::quietly(static fn () => stream_socket_server('tcp://127.0.0.1:0'));
        if ($decoy === false) {
            $this->markTestSkipped('cannot bind a loopback port');
        }
        $decoyName = (string) stream_socket_get_name($decoy, false);

        $this->installScript(self::TOKEN);
        $port = $this->startServer();
        [$status, $body] = $this->httpGet($port, '/host-check.php?token=' . self::TOKEN, $decoyName);
        [$status6, $body6] = $this->httpGet($port, '/host-check.php?token=' . self::TOKEN, '[::1]:' . $port);
        $this->stopServer();

        stream_set_blocking($decoy, false);
        $this->assertFalse(self::quietly(static fn () => stream_socket_accept($decoy, 0)), 'a self-request reached the decoy listener');
        fclose($decoy);

        foreach ([[$status, $body], [$status6, $body6]] as [$st, $out]) {
            $this->assertSame(200, $st);
            $data = $this->extractJson($out);
            $this->assertSame('http', $data['mode']);
            $this->assertSame('WARN', $data['checks']['web.server']['status']);
            // Self-requests ran (pinned to the server's own address and port).
            $this->assertSame('PASS', $data['checks']['web.authorization_header']['status']);
            $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
            $this->assertNoSensitiveText($out);
            $this->assertStringNotContainsString('::1', $out);
        }
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }

    public function testHttpSelftestOffWritesNoProbeFiles(): void
    {
        $this->installScript(self::TOKEN);
        $port = $this->startServer();
        [$status, $body] = $this->httpGet($port, '/host-check.php?selftest=0&token=' . self::TOKEN);
        $this->stopServer();
        $this->assertSame(200, $status);
        $data = $this->extractJson($body);
        $this->assertFalse($data['selftest']);
        $this->assertArrayNotHasKey('web.htaccess_deny', $data['checks']);
        $this->assertNoSensitiveText($body);
        $this->assertSame(['public', 'public/host-check.php'], $this->filesInWorkDir());
    }
}
