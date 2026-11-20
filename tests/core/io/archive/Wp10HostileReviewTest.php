<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Archive\ArchiveRefused;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Io\Archive\UntrustedProject;
use TiffinDB\Core\Io\Archive\ZipCheck;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\ExportSourceBuilder;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionStore;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Schema\Identifier;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * Hostile cases added by the M4-WP10 design-tier security review
 * (`docs/build/reviews/M4-sec-wp10.md`): schema objects smuggled next to
 * `_tiffin_collections`, collection keys that collide with other keys,
 * bounds on collection rows and rules, rules JSON that PHP decodes in
 * unusual ways, attachment rows and entries that do not match, and the
 * JSON export's handling of collections and attachments. Every name and
 * value is synthetic.
 */
#[CoversClass(UntrustedProject::class)]
#[CoversClass(ProjectRebuilder::class)]
final class Wp10HostileReviewTest extends TestCase
{
    use ArchiveFixture;

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-sec-wp10-review-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    private function row(string $key, string $table, string $name, string $rules = "'[]'", string $position = '1'): string
    {
        return 'INSERT INTO _tiffin_collections (key, table_key, name, match_mode, rules, sort, position, created_at, created_by, updated_at, updated_by)'
            . " VALUES ('" . $key . "', '" . $table . "', " . $name . ", 'all', " . $rules . ', NULL, ' . $position
            . ", '2026-11-12T03:15:00Z', NULL, '2026-11-12T03:15:00Z', NULL)";
    }

    private static function lit(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** @return array<string, string> */
    private function attachmentEntries(): array
    {
        $entries = [];
        foreach ([self::FILE_ONE, self::FILE_TWO] as $content) {
            $sha256 = hash('sha256', $content);
            $entries['attachments/' . substr($sha256, 0, 2) . '/' . $sha256] = $content;
        }

        return $entries;
    }

    /** Refusal reason of a copy of the source edited by $statements, or null when accepted. */
    private function refusalOf(string ...$statements): ?string
    {
        $copy = $this->copyOfSource();
        self::rawEdit($copy, ...$statements);
        try {
            $this->check($this->zipFor($copy, [], $this->attachmentEntries()));
        } catch (ArchiveRefused $refused) {
            return $refused->reason;
        }

        return null;
    }

    // ------------------------------------------------------------ schema objects

    public function testObjectsSmuggledBesideTheCollectionsTableAreRefused(): void
    {
        $cases = [
            'trigger' => ['CREATE TRIGGER _tiffin_collections_t AFTER INSERT ON _tiffin_collections BEGIN DELETE FROM _tiffin_tables; END'],
            'view' => ['CREATE VIEW _tiffin_collections_v AS SELECT * FROM _tiffin_collections'],
            'extra index' => ['CREATE INDEX _tiffin_collections_extra ON _tiffin_collections (sort)'],
            'expression index under a user name' => ['CREATE INDEX x_f_' . $this->f['Name']->key . '_u ON _tiffin_collections (lower(name))'],
            'table rebuilt with a check' => [
                'DROP TABLE _tiffin_collections',
                'CREATE TABLE _tiffin_collections (key TEXT PRIMARY KEY, table_key TEXT NOT NULL, name TEXT NOT NULL CHECK (abs(-9223372036854775808)), match_mode TEXT NOT NULL DEFAULT \'all\','
                    . ' rules TEXT NOT NULL DEFAULT \'[]\', sort TEXT, position INTEGER NOT NULL, created_at TEXT NOT NULL, created_by TEXT, updated_at TEXT NOT NULL, updated_by TEXT) WITHOUT ROWID',
            ],
            'table as a rowid table' => [
                'DROP TABLE _tiffin_collections',
                'CREATE TABLE _tiffin_collections (key TEXT PRIMARY KEY, table_key TEXT NOT NULL REFERENCES _tiffin_tables (key) ON DELETE CASCADE, name TEXT NOT NULL, match_mode TEXT NOT NULL DEFAULT \'all\','
                    . ' rules TEXT NOT NULL DEFAULT \'[]\', sort TEXT, position INTEGER NOT NULL, created_at TEXT NOT NULL, created_by TEXT, updated_at TEXT NOT NULL, updated_by TEXT)',
            ],
            'table missing' => ['DROP TABLE _tiffin_collections'],
        ];
        foreach ($cases as $label => $statements) {
            $reason = $this->refusalOf(...$statements);
            self::assertNotNull($reason, $label . ' was accepted');
            self::assertContains($reason, [ArchiveRefused::UNEXPECTED_OBJECT, ArchiveRefused::NOT_PROJECT], $label);
        }
    }

    public function testAnAutomaticIndexEntryOnTheCollectionsTableIsRefused(): void
    {
        // A rowid table with a TEXT PRIMARY KEY makes sqlite_autoindex__tiffin_collections_1; renamed in place it passes for the documented table only by its SQL.
        $reason = $this->refusalOf(
            'PRAGMA writable_schema = ON',
            "INSERT INTO sqlite_master (type, name, tbl_name, rootpage, sql) SELECT 'index', 'sqlite_autoindex__tiffin_collections_1', '_tiffin_collections', rootpage, NULL FROM sqlite_master WHERE name = '_tiffin_collections_table'",
            'PRAGMA writable_schema = OFF',
        );
        self::assertNotNull($reason);
        // SQLite itself rejects the orphan entry when it reads the schema (damaged); the object check is a second line behind it.
        self::assertContains($reason, [ArchiveRefused::UNEXPECTED_OBJECT, ArchiveRefused::DAMAGED]);
    }

    public function testAVersionThreeManifestWithACollectionsTableIsRefused(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit($copy, 'PRAGMA user_version = 3', "UPDATE _tiffin_meta SET value = '3' WHERE key = 'format_version'");
        try {
            $this->check($this->zipFor($copy, [], $this->attachmentEntries()));
            self::fail('A version 3 file with _tiffin_collections was accepted.');
        } catch (ArchiveRefused $refused) {
            self::assertSame(ArchiveRefused::UNEXPECTED_OBJECT, $refused->reason);
        }
    }

    // ------------------------------------------------------------ keys and bounds

    public function testCollectionKeysThatCollideAreRefused(): void
    {
        $items = $this->items->key;
        $formKey = (string) $this->db->value('SELECT "key" FROM "_tiffin_forms" LIMIT 1');
        foreach ([
            'table key' => $this->rooms->key,
            'field key' => $this->f['Price']->key,
            'relation key' => $this->room->key,
            'form key' => $formKey,
        ] as $label => $key) {
            self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusalOf($this->row($key, $items, "'Clash'")), $label);
        }
    }

    public function testBoundsOnCollectionRowsAndRulesText(): void
    {
        $items = $this->items->key;
        $many = ['DROP INDEX _tiffin_collections_name'];
        for ($i = 0; $i <= UntrustedProject::MAX_COLLECTION_ROWS; $i++) {
            $many[] = $this->row('c' . strtr(sprintf('%011d', $i), '0123456789', 'abcdefghij'), $items, "'Same'", position: (string) $i);
        }
        self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusalOf(...$many), '1001 rows');

        // Just over 1 MiB of rules over two rows; also as a blob, whose length counts in bytes.
        $half = 'hex(zeroblob(' . (UntrustedProject::MAX_RULES_TOTAL / 4 + 1) . '))';
        self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusalOf(
            $this->row('caaaaaaaaaaa', $items, "'A'", rules: $half),
            $this->row('cbbbbbbbbbbb', $items, "'B'", rules: $half),
        ), 'rules text');
        self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusalOf(
            $this->row('caaaaaaaaaaa', $items, "'A'", rules: 'zeroblob(' . (UntrustedProject::MAX_RULES_TOTAL + 1) . ')'),
        ), 'rules blob');
    }

    public function testManyRulesInsideTheBoundAreCutToTenQuickly(): void
    {
        $good = '{"field":"' . $this->f['Done']->key . '","op":"is","values":["1"]}';
        // About 1 MiB of rules in one row: 20,000 entries, ten kept.
        $rules = '[' . implode(',', array_fill(0, 20000, $good)) . ']';
        self::assertLessThan(UntrustedProject::MAX_RULES_TOTAL, strlen($rules));
        $copy = $this->copyOfSource();
        self::rawEdit($copy, $this->row('caaaaaaaaaaa', $this->items->key, "'Many rules'", rules: self::lit($rules)));
        $start = hrtime(true);
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));
        self::assertLessThan(5e9, hrtime(true) - $start);
        self::assertSame(19990, $report->collectionRulesDropped);
        self::assertCount(10, json_decode((string) $db->value('SELECT "rules" FROM "_tiffin_collections"'), true, 8, JSON_THROW_ON_ERROR));
    }

    public function testRulesJsonThatPhpDecodesUnusuallyNeverFailsTheRebuild(): void
    {
        $done = $this->f['Done']->key;
        $cases = [
            'NUL-led property name' => '[{"\u0000field":"x","field":"' . $done . '","op":"is","values":["1"]}]',
            'empty property name' => '[{"":"x","field":"' . $done . '","op":"is","values":["1"]}]',
            'duplicate members' => '[{"field":"' . $done . '","field":"' . $this->f['Name']->key . '","op":"is","values":["1"]}]',
            'values as an object' => '[{"field":"' . $done . '","op":"is","values":{"0":"1"}}]',
            'nested operand' => '[{"field":"' . $done . '","op":"is","values":[["1"]]}]',
            'huge number' => '[1e999999]',
            'big integer field' => '[{"field":123456789012345678901234567890,"op":"is","values":[]}]',
            'top-level string' => '"[]"',
            'byte order mark' => "\u{FEFF}[]",
            'bad escape' => '[{"field":"\ud800","op":"is","values":[]}]',
        ];
        $statements = ['DROP INDEX _tiffin_collections_name'];
        $i = 0;
        foreach ($cases as $label => $json) {
            $statements[] = $this->row('c' . strtr(sprintf('%011d', $i), '0123456789', 'abcdefghij'), $this->items->key, self::lit($label), rules: self::lit($json), position: (string) $i);
            $i++;
        }
        $copy = $this->copyOfSource();
        self::rawEdit($copy, ...$statements);
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));
        self::assertSame(0, $report->collectionsDropped);
        foreach ($db->all('SELECT "name", "rules" FROM "_tiffin_collections"') as $row) {
            $decoded = json_decode((string) $row['rules'], true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, (string) $row['name']);
            self::assertTrue(array_is_list($decoded), (string) $row['name']);
            foreach ($decoded as $rule) {
                CollectionRule::fromArray($rule);
            }
        }
        self::assertSame(['ok'], array_column($db->all('PRAGMA integrity_check'), 'integrity_check'));
    }

    public function testNamesThatDifferOnlyBeyondAsciiCaseAreBothKeptWithoutFailing(): void
    {
        $items = $this->items->key;
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'DROP INDEX _tiffin_collections_name',
            $this->row('caaaaaaaaaaa', $items, self::lit("\u{C9}t\u{E9}"), position: '1'),
            $this->row('cbbbbbbbbbbb', $items, self::lit("\u{E9}t\u{E9}"), position: '2'),
            $this->row('cccccccccccc', $items, self::lit("  Kite\u{A0}"), position: '3'),
            $this->row('cddddddddddd', $items, self::lit('KITE'), position: '4'),
        );
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));
        // NOCASE folds only ASCII, as strtolower does: the two accented names are distinct, the two Kite names are one.
        self::assertSame(["\u{C9}t\u{E9}", "\u{E9}t\u{E9}", 'Kite'], array_column($db->all('SELECT "name" FROM "_tiffin_collections" ORDER BY "position"'), 'name'));
        self::assertSame(1, $report->collectionsDropped);
    }

    public function testACollectionOnAFieldKeyOrJoinTableIsDropped(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            $this->row('caaaaaaaaaaa', $this->f['Name']->key, "'On a field'", position: '1'),
            $this->row('cbbbbbbbbbbb', $this->room->key, "'On a relation'", position: '2'),
            $this->row('cccccccccccc', Identifier::tableName($this->items->key), "'On a table name'", position: '3'),
        );
        [$report, $db] = $this->import($this->zipFor($copy, [], $this->attachmentEntries()));
        self::assertSame(3, $report->collectionsDropped);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM "_tiffin_collections"'));
        self::assertSame([], $db->all('PRAGMA foreign_key_check'));
    }

    // ------------------------------------------------------------ attachments

    public function testAttachmentRowsAndEntriesThatDoNotMatch(): void
    {
        $one = hash('sha256', self::FILE_ONE);
        $two = hash('sha256', self::FILE_TWO);
        $three = hash('sha256', 'synthetic orphan bytes');
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            // A hash with a path in it, a hash in capitals, and a second row for file two with another size.
            "INSERT INTO _tiffin_attachments (id, sha256, size, media_type, original_name, created_at) VALUES (3, '../../" . substr($one, 6) . "', 5, 'text/plain', '../../etc/passwd', '2026-11-12T03:15:00Z')",
            "INSERT INTO _tiffin_attachments (id, sha256, size, media_type, original_name, created_at) VALUES (4, '" . strtoupper($one) . "', 5, 'text/plain', 'a/b\\c.txt', '2026-11-12T03:15:00Z')",
            "INSERT INTO _tiffin_attachments (id, sha256, size, media_type, original_name, created_at) VALUES (5, '" . $two . "', 7, 'text/html', 'x.html', '2026-11-12T03:15:00Z')",
        );
        $entries = $this->attachmentEntries();
        $entries['attachments/' . substr($three, 0, 2) . '/' . $three] = 'synthetic orphan bytes';
        [$report, $db, $folder] = $this->import($this->zipFor($copy, [], $entries));

        self::assertSame(2, $report->attachmentsDropped);
        self::assertSame(1, $report->filesMissing, 'File two is named with two sizes, so it is not kept.');
        self::assertSame(1, $report->filesSkipped);
        self::assertFileExists($folder . '/attachments/' . substr($one, 0, 2) . '/' . $one);
        self::assertSame($one, hash_file('sha256', $folder . '/attachments/' . substr($one, 0, 2) . '/' . $one));
        self::assertFileDoesNotExist($folder . '/attachments/' . substr($two, 0, 2) . '/' . $two);
        self::assertFileDoesNotExist($folder . '/attachments/' . substr($three, 0, 2) . '/' . $three);
        // Nothing was written outside the attachments tree.
        $written = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $written[] = substr((string) $file, strlen($folder) + 1);
        }
        sort($written);
        self::assertSame(['attachments/' . substr($one, 0, 2) . '/' . $one, 'project.tiffin'], array_values(array_filter($written, static fn (string $p): bool => !str_starts_with($p, 'snapshots/') && !str_starts_with($p, 'project.tiffin-'))));
    }

    /** A zip written byte by byte: manifest, project, then one attachment entry named $name (central header) and $localName (local header). */
    private function rawZip(string $name, string $data, ?string $localName = null, int $size = -1): string
    {
        $bytes = (string) file_get_contents($this->copyOfSource());
        $manifest = json_encode([
            'format' => 'tiffindb-archive', 'archive_version' => 1, 'created_at' => self::CREATED, 'created_with' => self::VERSION_TEXT,
            'project_format_version' => 4, 'project_name' => 'Example Project', 'project_size' => strlen($bytes),
            'project_sha256' => hash('sha256', $bytes), 'attachments' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $options = $localName === null ? [] : ['localName' => $localName];
        if ($size >= 0) {
            $options['size'] = $size;
        }

        return (new ZipBytes())->add(ZipCheck::MANIFEST, $manifest)->add(ZipCheck::PROJECT, $bytes)->add($name, $data, $options)->write($this->jobFolder() . '/raw.zip');
    }

    public function testEntriesWithTraversalOrOddNamesAreRefused(): void
    {
        $one = hash('sha256', self::FILE_ONE);
        $good = 'attachments/' . substr($one, 0, 2) . '/' . $one;
        foreach ([
            'attachments/../' . $one,
            'attachments/' . substr($one, 0, 2) . '/../../' . $one,
            '../' . $one,
            '/' . $good,
            'attachments\\' . substr($one, 0, 2) . '\\' . $one,
            $good . "\0.txt",
            "\0" . $good,
            'attachments/zz/' . $one,
            strtoupper($good),
        ] as $name) {
            try {
                $this->check($this->rawZip($name, self::FILE_ONE));
                self::fail('Accepted entry ' . json_encode($name));
            } catch (ArchiveRefused $refused) {
                self::assertContains($refused->reason, [ArchiveRefused::ENTRY_NAME, ArchiveRefused::MISSING_ENTRY, ArchiveRefused::DUPLICATE], json_encode($name));
            }
        }
        // A local header naming a path outside the folder while the central directory names a good one: the bytes are only ever streamed to the computed target.
        try {
            [$report, , $folder] = $this->import($this->rawZip($good, self::FILE_ONE, '../../../escaped-' . $one));
            self::assertFileDoesNotExist(dirname($folder, 3) . '/escaped-' . $one);
            self::assertFileDoesNotExist(dirname($folder) . '/escaped-' . $one);
            $kept = $folder . '/attachments/' . substr($one, 0, 2) . '/' . $one;
            if (is_file($kept)) {
                self::assertSame($one, hash_file('sha256', $kept));
            } else {
                self::assertGreaterThan(0, $report->filesMissing);
            }
        } catch (ArchiveRefused $refused) {
            self::assertNotSame(ArchiveRefused::REBUILD_FAILED, $refused->reason);
        }
        // A declared size smaller than the bytes, for a hash a row names with that size: the stream stops after the declared size and the file is not kept.
        $bomb = str_repeat("\0", 4 << 20);
        $bombHash = hash('sha256', $bomb);
        $copy = $this->copyOfSource();
        self::rawEdit($copy, "INSERT INTO _tiffin_attachments (id, sha256, size, media_type, original_name, created_at) VALUES (3, '" . $bombHash . "', 10, 'text/plain', 'zeros.txt', '2026-11-12T03:15:00Z')");
        $bytes = (string) file_get_contents($copy);
        $manifest = json_encode([
            'format' => 'tiffindb-archive', 'archive_version' => 1, 'created_at' => self::CREATED, 'created_with' => self::VERSION_TEXT,
            'project_format_version' => 4, 'project_name' => 'Example Project', 'project_size' => strlen($bytes),
            'project_sha256' => hash('sha256', $bytes), 'attachments' => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $zip = (new ZipBytes())->add(ZipCheck::MANIFEST, $manifest)->add(ZipCheck::PROJECT, $bytes)
            ->add('attachments/' . substr($bombHash, 0, 2) . '/' . $bombHash, $bomb, ['size' => 10])->write($this->jobFolder() . '/bomb.zip');
        try {
            [$report, , $folder] = $this->import($zip);
            self::assertSame(3, $report->filesMissing, "The two source files are not in this zip, and the bomb is not kept.");
            self::assertFileDoesNotExist($folder . '/attachments/' . substr($bombHash, 0, 2) . '/' . $bombHash);
        } catch (ArchiveRefused $refused) {
            self::assertContains($refused->reason, [ArchiveRefused::TOO_LARGE, ArchiveRefused::COMPRESSION, ArchiveRefused::DAMAGED], $refused->reason);
        }
    }

    public function testAttachmentBytesSurviveARoundTrip(): void
    {
        $binary = '';
        for ($i = 0; $i < 70000; $i++) {
            $binary .= chr(($i * 31 + 7) % 256);
        }
        $sha256 = $this->attach(3, $binary, 'example.bin', 'application/octet-stream', $this->itemIds[1]);
        [$report, $db, $folder] = $this->import($this->export());
        self::assertTrue($report->isClean());
        foreach ($db->all('SELECT "sha256", "size" FROM "_tiffin_attachments"') as $row) {
            $path = $folder . '/attachments/' . substr($row['sha256'], 0, 2) . '/' . $row['sha256'];
            self::assertSame($row['sha256'], hash_file('sha256', $path));
            self::assertSame($row['size'], filesize($path));
        }
        self::assertSame($sha256, hash_file('sha256', $folder . '/attachments/' . substr($sha256, 0, 2) . '/' . $sha256));
    }

    // ------------------------------------------------------------ JSON export

    private function jsonExporter(): Exporter
    {
        $root = dirname(__DIR__, 4) . '/plugins';
        foreach ((new PluginScanner($root))->scan(['export/json' => true]) as $plugin) {
            if ($plugin->ref() === 'export/json') {
                self::assertSame(PluginStatus::Enabled, $plugin->status);

                return (new PluginLoader($root))->exporter($plugin);
            }
        }
        self::fail('The export/json plugin was not found.');
    }

    public function testJsonExportHasCollectionsLastAndNoFileBytesOrPaths(): void
    {
        $store = new CollectionStore($this->db, $this->schema(), $this->keys, $this->clock);
        $store->create($this->items->key, 'Quote " and </script> name', MatchMode::Any, [
            new CollectionRule($this->f['Name']->key, 'contains', ["\u{2028}</script><!--"]),
        ], null, self::USER_KEY);
        $store->create($this->rooms->key, 'Rooms only', MatchMode::All, [], 'added.asc', self::USER_KEY);
        $path = $this->jobFolder() . '/export.json';
        $exporter = $this->jsonExporter();

        $one = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $this->rooms->key, $this->clock, self::VERSION_TEXT);
        $exporter->write($one, $exporter->settings('table'), $path);
        $doc = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(['Rooms only'], array_column($doc['collections'], 'name'), 'Only collections of exported tables.');
        unlink($path);

        $all = ExportSourceBuilder::build($this->db, $this->schema(), $this->types, null, $this->clock, self::VERSION_TEXT);
        $exporter->write($all, $exporter->settings('project'), $path);
        $text = (string) file_get_contents($path);
        $doc = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('collections', array_key_last($doc));
        self::assertSame(1, $doc['format_version'] ?? $doc['version'] ?? 1);
        self::assertSame(['key', 'table', 'name', 'match', 'rules', 'sort', 'created_at', 'updated_at'], array_keys($doc['collections'][0]));
        self::assertSame("\u{2028}</script><!--", $doc['collections'][0]['rules'][0]['values'][0]);
        self::assertStringNotContainsString(self::FILE_ONE, $text);
        self::assertStringNotContainsString(self::FILE_TWO, $text);
        self::assertStringNotContainsString(base64_encode(self::FILE_ONE), $text);
        self::assertStringNotContainsString('attachments/', $text);
        self::assertStringNotContainsString($this->folder, $text);
        self::assertStringNotContainsString('created_by', json_encode($doc['collections'], JSON_THROW_ON_ERROR), 'No person keys in collections beyond the format.');
    }
}
