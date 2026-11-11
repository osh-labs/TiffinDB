<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Runs tools/discovery/discover-structure.php against a synthetic package
 * built at runtime. All fixture values are obviously synthetic.
 */
final class DiscoverStructureTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../tools/discovery/discover-structure.php';

    /** Every row value, default literal and personal-looking name in the fixture. */
    private const SECRETS = [
        'Example Person',
        'Sample Human',
        'user@example.com',
        'other@example.com',
        '555-0100',
        'Synthetic Default',
        'Synthetic Label',
        'kind:v2',
        'code#07',
        'holiday snapshot',
    ];

    private string $dir = '';
    private string $package = '';
    private string $database = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->dir = sys_get_temp_dir() . '/tiffindb-discover-test-' . bin2hex(random_bytes(6));
        $this->package = $this->dir . '/Example Person Library';
        mkdir($this->package . '/Attachments', 0700, true);
        $this->database = $this->package . '/store.fixturedb';

        $pdo = new PDO('sqlite:' . $this->database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA user_version = 7');
        $pdo->exec(
            "CREATE TABLE contacts (
                contact_id INTEGER PRIMARY KEY,
                display_name TEXT NOT NULL DEFAULT 'Synthetic Default',
                email TEXT,
                phone TEXT,
                score NUMERIC DEFAULT 1.5,
                picture BLOB
            )"
        );
        $pdo->exec(
            'CREATE TABLE field_meta (
                meta_id INTEGER PRIMARY KEY,
                label TEXT,
                type_code TEXT,
                contact_ref INTEGER REFERENCES contacts(contact_id)
            )'
        );
        $pdo->exec('CREATE INDEX idx_contacts_email ON contacts(email)');
        $pdo->exec(
            "INSERT INTO contacts VALUES
                (1, 'Example Person', 'user@example.com', '555-0100', 2.5, x'00010203'),
                (2, 'Sample Human', 'other@example.com', NULL, NULL, NULL),
                (3, 'Example Person', NULL, NULL, 4, NULL)"
        );
        $pdo->exec(
            "INSERT INTO field_meta VALUES
                (1, 'Synthetic Label', 'kind:v2', 1),
                (2, 'Synthetic Label', 'code#07', 2)"
        );
        $pdo = null;

        file_put_contents($this->package . '/Attachments/holiday snapshot.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('j', 32));
        file_put_contents($this->package . '/Attachments/Example Person.txt', 'user@example.com');
        file_put_contents($this->package . '/Info.plist', "<?xml version=\"1.0\"?>\n<plist version=\"1.0\"><dict/></plist>\n");
        file_put_contents($this->package . '/damaged.sqlite', "SQLite format 3\0" . str_repeat("\x01", 600));

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();
            $zip->open($this->package . '/Sample Human export.zip', ZipArchive::CREATE);
            $zip->addFile($this->database, 'Sample Human/inner.fixturedb');
            $zip->close();
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }
    }

    public function testDefaultOutputContainsStructureButNoRowValues(): void
    {
        [$code, $out] = $this->discover([$this->package]);

        // The damaged database is reported, which gives exit code 1.
        self::assertSame(1, $code);
        self::assertStringContainsString('discover-structure.php', $out);
        self::assertStringContainsString('no row values', $out);
        $this->assertNoSecrets($out);

        self::assertStringContainsString('-- table contacts rows: 3', $out);
        self::assertStringContainsString('-- table field_meta rows: 2', $out);
        foreach (['contact_id', 'display_name', 'email', 'phone', 'score', 'picture', 'type_code'] as $column) {
            self::assertMatchesRegularExpression('/column \d+: ' . $column . ' \|/', $out);
        }
        self::assertStringContainsString('user_version=7', $out);
        self::assertStringContainsString('encoding=UTF-8', $out);
        self::assertStringContainsString('page_size=', $out);
        self::assertStringContainsString('idx_contacts_email', $out);
        self::assertStringContainsString('contact_ref -> contacts(contact_id)', $out);

        // display_name: 3 text values of 14, 12 and 14 bytes.
        self::assertStringContainsString(
            'null=0 non-null=3 | integer=0 real=0 text=3 blob=0 | text_bytes min=12 max=14 avg=13.3',
            $out
        );
        // score: one NULL, one REAL, one INTEGER; no lengths for numbers.
        self::assertStringContainsString("null=1 non-null=2 | integer=1 real=1 text=0 blob=0\n", $out);
        // picture: one 4-byte BLOB.
        self::assertStringContainsString('blob_bytes min=4 max=4 avg=4', $out);

        // Defaults: numeric shown, text hidden, also inside the DDL.
        self::assertStringContainsString('default=1.5', $out);
        self::assertStringContainsString('default=present (non-numeric, hidden)', $out);
        self::assertStringContainsString("DEFAULT '<text:17>'", $out);

        self::assertStringContainsString('SQLITE_NOTADB', $out);
    }

    public function testFileNamesAreRedactedByDefault(): void
    {
        [, $out] = $this->discover([$this->package]);

        self::assertStringNotContainsString('holiday snapshot', $out);
        self::assertStringNotContainsString('Example Person', $out);
        self::assertMatchesRegularExpression('/image-jpeg\s+36\s+Attachments\/<name-\d+>\.jpg/', $out);
        self::assertMatchesRegularExpression('/plist-xml\s+\d+\s+Info\.plist/', $out);
        self::assertMatchesRegularExpression('/sqlite\s+\d+\s+<name-\d+>\.fixturedb/', $out);

        [, $shown] = $this->discover(['--show-names', $this->package]);
        self::assertStringContainsString('Attachments/holiday snapshot.jpg', $shown);
        self::assertStringContainsString('store.fixturedb', $shown);
    }

    public function testZipArchivesAreListedAndTheirDatabasesAnalysed(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('The zip extension is not available.');
        }
        [, $out] = $this->discover([$this->package]);

        self::assertMatchesRegularExpression('/sqlite\s+\d+\s+<name-\d+>\.zip!\/<name-\d+>\/<name-\d+>\.fixturedb/', $out);
        self::assertSame(2, substr_count($out, '-- table contacts rows: 3'));
        $this->assertNoSecrets($out);
    }

    public function testJsonOutputIsValidDeterministicAndFreeOfValues(): void
    {
        [, $first] = $this->discover(['--json', $this->package]);
        [, $second] = $this->discover(['--json', $this->package]);

        self::assertSame($first, $second);
        $this->assertNoSecrets($first);
        $report = json_decode($first, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('discover-structure.php', $report['tool']);

        $tables = [];
        foreach ($report['databases'] as $database) {
            foreach ($database['tables'] ?? [] as $table) {
                $tables[$table['name']] = $table;
            }
        }
        self::assertSame(3, $tables['contacts']['row_count']);
        self::assertSame('email', $tables['contacts']['columns'][2]['name']);
        self::assertSame(1, $tables['contacts']['columns'][2]['statistics']['null']);
        self::assertSame(2, $tables['contacts']['columns'][2]['statistics']['storage_classes']['text']);
    }

    public function testValueShapesMasksLettersAndDigitsForTheNamedTableOnly(): void
    {
        [, $out] = $this->discover(['--value-shapes', 'field_meta', $this->package]);

        self::assertStringContainsString('WARNING: --value-shapes was used', $out);
        self::assertStringContainsString('[aaaa:a9]', $out);
        self::assertStringContainsString('[aaaa#99]', $out);
        self::assertStringContainsString('2 x  [Aaaaaaaaa Aaaaa]', $out);
        // Nothing from the other table: no shape of an e-mail address or phone number.
        self::assertStringNotContainsString('@', $out);
        self::assertStringNotContainsString('[999-9999]', $out);
        $this->assertNoSecrets($out);

        [, $default] = $this->discover([$this->package]);
        self::assertStringNotContainsString('VALUE SHAPES', $default);
        self::assertStringNotContainsString('[aaaa:a9]', $default);
    }

    public function testRedactIdentifiersHashesConsistently(): void
    {
        [, $out] = $this->discover(['--redact-identifiers=all', $this->package]);

        self::assertStringNotContainsString('display_name', $out);
        self::assertStringNotContainsString('field_meta', $out);
        self::assertMatchesRegularExpression('/-- table (id_[0-9a-f]{10}) rows: 3\n\s+sql: CREATE TABLE \1 \(/', $out);
        $this->assertNoSecrets($out);
    }

    public function testInputIsNotModifiedAndNothingIsWrittenBesideIt(): void
    {
        $before = $this->snapshot();

        $this->discover([$this->package]);
        $this->discover(['--json', '--value-shapes', 'field_meta', '--apply-wal', $this->package]);
        $this->discover([$this->database]);

        self::assertSame($before, $this->snapshot());
    }

    public function testOutOptionWritesTheReportButRefusesTheInputDirectory(): void
    {
        [$code, , $err] = $this->discover(['--out', $this->package . '/report.txt', $this->package]);
        self::assertSame(3, $code);
        self::assertStringContainsString('Refusing', $err);
        self::assertFileDoesNotExist($this->package . '/report.txt');

        $target = $this->dir . '/report.txt';
        [, $out] = $this->discover(['--out', $target, $this->package]);
        self::assertSame('', $out);
        self::assertStringContainsString('-- table contacts rows: 3', (string) file_get_contents($target));
    }

    public function testUsageAndMissingInputExitCodes(): void
    {
        self::assertSame(2, $this->discover([])[0]);
        self::assertSame(2, $this->discover(['--no-such-option', $this->package])[0]);
        self::assertSame(3, $this->discover([$this->dir . '/does-not-exist'])[0]);
    }

    private function assertNoSecrets(string $output): void
    {
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function discover(array $arguments): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, self::SCRIPT], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string,string> relative path => SHA-256 of every file in the package */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->package, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($this->package))] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path . '/' . $name;
            is_dir($child) && !is_link($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
