<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Plugin\Import\Xlsx\Limits;
use TiffinDB\Plugin\Import\Xlsx\Styles;
use TiffinDB\Plugin\Import\Xlsx\WorkbookCheck;
use TiffinDB\Plugin\Import\Xlsx\Xml;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

require_once __DIR__ . '/WorkbookBuilder.php';
require_once __DIR__ . '/XlsxPlugins.php';

/**
 * The importer's resource limits (security review of M3-WP5): text is
 * never built as a whole subtree in libxml's memory (run in a separate
 * process at memory_limit=128M, the peak resident memory bounded), real
 * bytes are counted against declared sizes, every call's parsing work is
 * bounded by step budgets (measured with Xml::steps(), not only the
 * clock), and the job cache saves repeated work and is read back as
 * untrusted data.
 */
#[CoversNothing]
final class XlsxLimitsTest extends TestCase
{
    private TempDir $temp;
    private Importer $importer;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-xlsx-limits-test-');
        mkdir($this->temp->path('tmp'));
        $this->importer = XlsxPlugins::importer();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function file(string $path): SourceFile
    {
        return new SourceFile($path, 'xlsx', (int) filesize($path), $this->temp->path('tmp'));
    }

    /**
     * Writes a workbook whose part $part is $head, then $bytes of child
     * elements, then $tail; built on disk in pieces so the test itself
     * holds little memory.
     */
    private function childrenWorkbook(string $part, string $head, int $bytes, string $tail): string
    {
        $child = '';
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        for ($i = 0; $i < 4000; $i++) {
            $child .= '<x>' . str_repeat('y', 120) . $letters[($i * 7) % 26] . '</x>';
        }
        $raw = $this->temp->path('part.xml');
        $handle = fopen($raw, 'wb');
        fwrite($handle, $head);
        for ($written = 0; $written < $bytes; $written += strlen($child)) {
            fwrite($handle, $child);
        }
        fwrite($handle, $tail);
        fclose($handle);
        $builder = (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['Heading']) . '<row r="2"><c r="A2" t="s"><v>0</v></c></row>')
            ->sharedStrings(['<t>shared</t>']);
        $path = $this->temp->path('upload.bin');
        $parts = $builder->parts();
        unset($parts[$part]);
        WorkbookBuilder::zip($path, $parts);
        $zip = new \ZipArchive();
        $zip->open($path);
        $zip->addFile($raw, $part);
        $zip->close();
        unlink($raw);

        return $path;
    }

    /**
     * Reads $path in a separate PHP process at memory_limit=128M (detect,
     * inspect, every row) and returns its report: reason (null or a
     * PluginFailure reason), rows, peak resident megabytes, exit code.
     *
     * @return array{reason: ?string, rows: int, rss: float, code: int, output: string}
     */
    private function readInChildProcess(string $path): array
    {
        $script = $this->temp->path('child.php');
        $root = RepoFiles::root();
        file_put_contents($script, '<?php
require ' . var_export($root . '/vendor/autoload.php', true) . ';
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
$plugins = ' . var_export($root . '/plugins', true) . ';
foreach ((new PluginScanner($plugins))->scan([]) as $info) {
    if ($info->ref() === "import/xlsx") { $importer = (new PluginLoader($plugins))->importer($info); }
}
$path = $argv[1];
$file = new SourceFile($path, "xlsx", filesize($path), $argv[2]);
$reason = null; $rows = 0;
try {
    $settings = $importer->detect($file);
    $importer->inspect($file, $settings);
    foreach ($importer->rows($file, $settings, 0, 0, 2000) as $row) { $rows++; }
} catch (PluginFailure $failure) { $reason = $failure->reason; }
preg_match("/VmHWM:\\\\s+(\\\\d+)/", (string) file_get_contents("/proc/self/status"), $m);
echo json_encode(["reason" => $reason, "rows" => $rows, "rss" => isset($m[1]) ? $m[1] / 1024 : 0.0]);
');
        $command = [PHP_BINARY, '-d', 'memory_limit=128M'];
        array_push($command, $script, $path, $this->temp->path('tmp'));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $report = json_decode(trim($output), true);

        return [
            'reason' => is_array($report) ? $report['reason'] : 'crashed',
            'rows' => is_array($report) ? (int) $report['rows'] : -1,
            'rss' => is_array($report) ? (float) $report['rss'] : 0.0,
            'code' => $code,
            'output' => substr($output, 0, 300),
        ];
    }

    public function testChildElementsWhereTextIsExpectedAreNotBuiltInMemory(): void
    {
        if (!is_readable('/proc/self/status')) {
            self::markTestSkipped('Peak resident memory is read from /proc.');
        }
        $main = WorkbookBuilder::HEAD . '<worksheet xmlns="' . WorkbookBuilder::MAIN . '"><sheetData>' . WorkbookBuilder::textRow(1, ['Heading']);
        $cases = [
            '<t> children' => ['xl/worksheets/sheet1.xml', $main . '<row r="2"><c r="A2" t="inlineStr"><is><t>', '</t></is></c></row></sheetData></worksheet>'],
            '<v> children' => ['xl/worksheets/sheet1.xml', $main . '<row r="2"><c r="A2"><v>', '</v></c></row></sheetData></worksheet>'],
            '<si> children' => ['xl/sharedStrings.xml', WorkbookBuilder::HEAD . '<sst xmlns="' . WorkbookBuilder::MAIN . '"><si><t>', '</t></si></sst>'],
        ];
        foreach ($cases as $label => [$part, $head, $tail]) {
            $path = $this->childrenWorkbook($part, $head, 96 * 1048576, $tail);
            self::assertLessThan(3 * 1048576, (int) filesize($path), $label . ': a small upload');
            $report = $this->readInChildProcess($path);
            self::assertSame(0, $report['code'], $label . ': ' . $report['output']);
            self::assertNull($report['reason'], $label);
            self::assertSame(1, $report['rows'], $label);
            self::assertLessThan(96.0, $report['rss'], $label . ': peak resident memory in MB');
            array_map('unlink', glob($this->temp->path('tmp') . '/*') ?: []);
        }
    }

    public function testRealBytesAreCountedAgainstTheDeclaredSize(): void
    {
        $parts = (new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['Heading']))->parts();
        $real = $parts['xl/worksheets/sheet1.xml'] . str_repeat('<!-- more than declared -->', 100000);
        $entries = [];
        foreach ($parts as $name => $bytes) {
            $entries[] = $name === 'xl/worksheets/sheet1.xml'
                ? ['name' => $name, 'data' => (string) gzdeflate($real), 'method' => 8, 'size' => 900000, 'crc' => crc32($real)]
                : ['name' => $name, 'data' => $bytes];
        }
        $path = $this->temp->path('lie.bin');
        file_put_contents($path, WorkbookBuilder::rawZip($entries));
        self::assertArrayHasKey('xl/worksheets/sheet1.xml', WorkbookCheck::directory($path), 'The directory alone passes.');
        try {
            WorkbookCheck::contents($path);
            self::fail('A lying size was not found.');
        } catch (PluginFailure $failure) {
            self::assertSame(PluginFailure::REASON_UNREADABLE, $failure->reason);
            self::assertStringContainsString('more bytes than its header declares', $failure->getMessage());
        }
        $file = $this->file($path);
        self::assertSame(PluginFailure::REASON_UNREADABLE, XlsxPlugins::failure(fn () => $this->importer->detect($file)));

        $short = $entries;
        foreach ($short as $i => $entry) {
            if ($entry['name'] === 'xl/worksheets/sheet1.xml') {
                $short[$i]['size'] = strlen($real) + 10;
            }
        }
        file_put_contents($path, WorkbookBuilder::rawZip($short));
        self::assertSame(PluginFailure::REASON_UNREADABLE, XlsxPlugins::failure(static fn () => WorkbookCheck::contents($path)), 'Fewer bytes than declared.');
    }

    public function testRepeatedCallsUseTheJobCache(): void
    {
        $rows = WorkbookBuilder::textRow(1, ['Name', 'Value']);
        $strings = [];
        for ($r = 2; $r <= 3001; $r++) {
            $strings[] = '<t>Item ' . $r . '</t>';
            $rows .= '<row r="' . $r . '"><c r="A' . $r . '" t="s"><v>' . ($r - 2) . '</v></c><c r="B' . $r . '"><v>' . $r . '</v></c></row>';
        }
        $file = $this->file((new WorkbookBuilder())->sheet('S', $rows)->sharedStrings($strings)->write($this->temp->path('upload.bin')));
        $steps = static function (\Closure $call): int {
            $before = Xml::steps();
            $call();

            return Xml::steps() - $before;
        };
        $importer = $this->importer;
        $settings = $importer->detect($file);
        $first = $steps(static fn () => $importer->inspect($file, $settings));
        $second = $steps(static fn () => $importer->inspect($file, $settings));
        self::assertGreaterThan(3000, $first, 'The first inspection scans rows and loads the shared strings.');
        self::assertLessThan(200, $second, 'The second one reads only the workbook structure.');
        $chunk = $steps(static fn () => iterator_to_array($importer->rows($file, $settings, 0, 1000, 1000), false));
        self::assertLessThan(1000 * 12 + 3000, $chunk, 'Skipping 1,000 rows and reading 1,000: no column scan, no shared strings.');
        self::assertSame(['Item 1002', '1002'], iterator_to_array($importer->rows($file, $settings, 0, 1000, 1), false)[0]);
        self::assertFileExists($this->temp->path('tmp/xlsx-cache.json'));
    }

    public function testTheCacheIsReadAsUntrustedData(): void
    {
        $file = $this->file((new WorkbookBuilder())->sheet('S', WorkbookBuilder::textRow(1, ['Name']) . '<row r="2"><c r="A2" t="s"><v>0</v></c></row>')
            ->sharedStrings(['<t>real</t>'])->write($this->temp->path('upload.bin')));
        $importer = $this->importer;
        $settings = $importer->detect($file);
        self::assertSame(['Name'], $importer->inspect($file, $settings)->tables[0]->columns);
        $cache = $this->temp->path('tmp/xlsx-cache.json');
        $data = json_decode((string) file_get_contents($cache), true);
        self::assertIsArray($data);

        $tampered = [
            'other key' => ['key' => str_repeat('0', 64) . ':1'] + $data,
            'bad columns' => ['columns' => ['0:1' => [str_repeat('x', 90000)], '0:0' => ['A'], '../x' => ['B']]] + $data,
            'bad styles' => ['styles' => base64_encode("\x07\x09")] + $data,
            'bad strings' => ['strings' => ['count' => -1, 'bytes' => 4, 'text' => 'x', 'offsets' => 'y']] + $data,
            'not JSON' => null,
        ];
        foreach ($tampered as $label => $value) {
            file_put_contents($cache, $value === null ? '{"v":1,' : (string) json_encode($value));
            self::assertSame(['Name'], $importer->inspect($file, $settings)->tables[0]->columns, $label);
            self::assertSame([['real']], iterator_to_array($importer->rows($file, $settings, 0, 0, 10), false), $label);
        }
        file_put_contents($this->temp->path('tmp/xlsx-strings.bin'), 'fake');
        self::assertSame([['real']], iterator_to_array($importer->rows($file, $settings, 0, 0, 10), false), 'A changed strings file is not used.');
        file_put_contents($cache, str_repeat(' ', Limits::MAX_CACHE_BYTES + 1));
        self::assertSame([['real']], iterator_to_array($importer->rows($file, $settings, 0, 0, 10), false), 'An oversized cache is not read.');
    }

    public function testEveryCallsParsingWorkIsBounded(): void
    {
        // 40 sheets of 16 rows of 16,000 empty cells: about ten million reads to scan.
        $row = str_repeat('<c/>', 16000);
        $data = '';
        for ($r = 1; $r <= 16; $r++) {
            $data .= '<row r="' . $r . '">' . $row . '</row>';
        }
        $builder = new WorkbookBuilder();
        for ($s = 1; $s <= 40; $s++) {
            $builder->sheet('S' . $s, $data);
        }
        $file = $this->file($builder->write($this->temp->path('upload.bin')));
        $importer = $this->importer;
        $settings = $importer->detect($file);
        $before = Xml::steps();
        self::assertSame(PluginFailure::REASON_TOO_LARGE, XlsxPlugins::failure(static fn () => $importer->inspect($file, $settings)));
        self::assertLessThanOrEqual(Limits::STEPS_COLUMNS + Limits::STEPS_STRUCTURE + Limits::STEPS_STYLES + 10, Xml::steps() - $before);

        $before = Xml::steps();
        self::assertNull(XlsxPlugins::failure(static fn () => $importer->rows($file, $settings, 39, 0, 10)), 'One sheet alone is within the limits.');
        self::assertLessThan(26 * 16000 + 20000, Xml::steps() - $before, 'One column scan (16 rows) and ten rows.');
    }

    public function testNumberFormatCodesAreClassifiedInBoundedTime(): void
    {
        $start = hrtime(true);
        self::assertSame(Styles::NUMBER, Styles::classify(str_repeat('[', 900000)), 'Longer than any real format.');
        self::assertSame(Styles::NUMBER, Styles::classify(str_repeat('[', Limits::MAX_FORMAT_CODE)));
        self::assertSame(Styles::DATE, Styles::classify(str_repeat('[', 200) . '] yyyy'));
        self::assertSame(Styles::DURATION, Styles::classify('[[[h]:mm'));
        self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
    }
}
