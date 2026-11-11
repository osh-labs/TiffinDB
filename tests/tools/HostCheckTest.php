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
 * Runs tools/host-check/host-check.php through the PHP CLI in a temporary
 * directory. Offline: CLI mode makes no network requests.
 */
#[CoversNothing]
final class HostCheckTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'php.version', 'php.sapi', 'ext.pdo_sqlite', 'ext.zip', 'ext.xmlreader', 'ext.mbstring',
        'ext.fileinfo', 'ext.openssl', 'ext.session', 'crypto.random_bytes', 'crypto.argon2id',
        'sqlite.version', 'sqlite.fts5', 'sqlite.json1',
        'limit.memory_limit', 'limit.max_execution_time', 'limit.upload_max_filesize',
        'limit.post_max_size', 'limit.max_file_uploads', 'limit.max_input_vars',
        'php.disable_functions', 'fs.outside_webroot_writable', 'fs.beside_script_writable',
        'fs.sqlite_file', 'fs.sqlite_journal_mode', 'fs.flock', 'fs.cleanup',
        'web.server', 'web.https', 'web.selftest',
    ];

    private string $workDir = '';

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        $this->workDir = $base . '/tiffindb-hostcheck-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir . '/public', 0700, true);
        copy(dirname(__DIR__, 2) . '/tools/host-check/host-check.php', $this->workDir . '/public/host-check.php');
    }

    protected function tearDown(): void
    {
        if ($this->workDir !== '' && is_dir($this->workDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->workDir);
        }
    }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    private function runScript(string ...$args): array
    {
        return $this->runScriptWithIni([], ...$args);
    }

    /**
     * @param list<string> $ini php -d settings, e.g. ['disable_functions=flock']
     * @return array{0:int,1:string,2:string}
     */
    private function runScriptWithIni(array $ini, string ...$args): array
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

    public function testCliReportIsCompleteSafeAndLeavesNoFiles(): void
    {
        [$code, $out] = $this->runScript();
        $data = $this->extractJson($out);

        $this->assertSame('tiffindb-host-check', $data['tool']);
        $this->assertSame('cli', $data['mode']);
        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $data['checks'], "missing check $key");
            $this->assertContains($data['checks'][$key]['status'], ['PASS', 'WARN', 'FAIL', 'INFO']);
        }

        // Exit code: 0 when no FAIL, 1 when at least one FAIL.
        $this->assertSame($data['summary']['fail'] > 0 ? 1 : 0, $code);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);

        // No absolute paths of the working directory, no host name.
        $this->assertStringNotContainsString($this->workDir, $out);
        $real = realpath($this->workDir);
        $this->assertStringNotContainsString((string) $real, $out);
        $host = (string) gethostname();
        if (strlen($host) >= 3) {
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_-])' . preg_quote($host, '/') . '(?![A-Za-z0-9_-])/', $out);
        }

        // Only the copied script remains.
        $left = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) {
            $left[] = substr($item->getPathname(), strlen($this->workDir) + 1);
        }
        $this->assertSame(['public/host-check.php'], $left);
    }

    public function testHelpExitsZeroAndUnknownOptionExitsTwo(): void
    {
        [$code, $out] = $this->runScript('--help');
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Exit code', $out);

        [$code] = $this->runScript('--bogus');
        $this->assertSame(2, $code);
    }

    public function testFailingCheckGivesExitCodeOne(): void
    {
        [$code, $out] = $this->runScriptWithIni(['disable_functions=flock']);
        $data = $this->extractJson($out);
        $this->assertSame(1, $code);
        $this->assertSame('FAIL', $data['checks']['php.disable_functions']['status']);
        $this->assertSame('FAIL', $data['checks']['fs.flock']['status']);
        $this->assertSame('PASS', $data['checks']['fs.cleanup']['status']);
    }
}
