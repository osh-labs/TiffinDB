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
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * Memory is bounded however large the values in an archive's database
 * are (M3 spec section 11.4: no database content may reach the request as
 * an error page). Oversized rows are refused, or read as null and dropped
 * or emptied, before PHP holds them; record pages are limited by bytes,
 * not only rows. The two large cases run the check and the rebuild in a
 * separate PHP process with memory_limit 128M, where reading the values
 * into PHP would end the process with a fatal error.
 */
#[CoversClass(UntrustedProject::class)]
#[CoversClass(ProjectRebuilder::class)]
final class MemoryBoundsTest extends TestCase
{
    use ArchiveFixture;

    private const LIMIT = '128M';

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-memory-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testThreeHundredMegabyteFormIsDroppedUnderALowMemoryLimit(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit($copy, "UPDATE _tiffin_forms SET html = html || '<!--' || hex(zeroblob(150000000)) || '-->' WHERE table_key = '" . $this->items->key . "'");
        self::assertGreaterThan(300000000, filesize($copy));
        $result = $this->probe($copy);
        self::assertSame('rebuilt', $result['outcome']);
        self::assertSame(1, $result['formsDropped']);
        self::assertLessThan(64 * 1048576, $result['peak']);
    }

    public function testManyLargeValuesStayWithinALowMemoryLimit(): void
    {
        $copy = $this->copyOfSource();
        $items = '"' . Identifier::tableName($this->items->key) . '"';
        $notes = '"' . Identifier::columnName($this->f['Notes']->key) . '"';
        self::rawEdit(
            $copy,
            // 150 values of 1,000,000 characters each, beyond the field's 20,000: 150 MB if a page were read whole.
            'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 150)'
            . ' INSERT INTO ' . $items . ' ("_created_at", "_updated_at", ' . $notes . ") SELECT '" . self::START . "', '" . self::START . "', hex(zeroblob(500000)) FROM n",
            // And 3,000 values at the field's limit, which are valid and copied.
            'WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 3000)'
            . ' INSERT INTO ' . $items . ' ("_created_at", "_updated_at", ' . $notes . ") SELECT '" . self::START . "', '" . self::START . "', hex(zeroblob(10000)) FROM n",
        );
        $result = $this->probe($copy);
        self::assertSame('rebuilt', $result['outcome']);
        self::assertSame(150, $result['valuesEmptied']);
        self::assertSame(5 + 3150, $result['records']);
        self::assertLessThan(96 * 1048576, $result['peak']);
    }

    public function testOversizedFormIsDroppedAndRegenerated(): void
    {
        $zip = $this->hostileArchive("UPDATE _tiffin_forms SET html = html || '<!--' || hex(zeroblob(140000)) || '-->' WHERE table_key = '" . $this->items->key . "'");
        [$report, $db] = $this->import($zip);
        self::assertSame(1, $report->formsDropped);
        self::assertSame(1, $db->value('SELECT COUNT(*) FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1 AND "generated" = 1', [$this->items->key]));
    }

    public function testOversizedValueIsEmptiedWithoutBeingRead(): void
    {
        $zip = $this->hostileArchive(
            'UPDATE "' . Identifier::tableName($this->items->key) . '" SET "' . Identifier::columnName($this->f['Name']->key) . '" = hex(zeroblob(1000)),'
            . ' "_created_by" = hex(zeroblob(100)), "' . Identifier::columnName($this->f['Bought']->key) . '" = hex(zeroblob(1000)) WHERE "id" = ' . $this->itemIds[0],
        );
        [$report, $db] = $this->import($zip);
        self::assertSame(2, $report->valuesEmptiedTotal());
        self::assertSame(1, $report->personKeysCleared);
        self::assertNull($db->value('SELECT "_created_by" FROM "' . Identifier::tableName($this->items->key) . '" WHERE "id" = ?', [$this->itemIds[0]]));
    }

    public function testMetadataBeyondTheCapsIsRefused(): void
    {
        $tables = [];
        for ($i = 0; $i < 199; $i++) {
            $key = strtr(sprintf('a%011d', $i), '0123456789', 'abcdefghij');
            $tables[] = "INSERT INTO _tiffin_tables (key, sql_name, name, item_name, position, created_at, updated_at) VALUES ('" . $key . "', 't_" . $key . "', 'N" . $i . "', 'n', 9, '" . self::START . "', '" . self::START . "')";
        }
        $cases = [
            'too many tables' => $tables,
            'huge table name' => ["UPDATE _tiffin_tables SET name = hex(zeroblob(1000)) WHERE key = '" . $this->rooms->key . "'"],
            'huge field options' => ["UPDATE _tiffin_fields SET options = '{\"x\":\"' || hex(zeroblob(40000)) || '\"}' WHERE key = '" . $this->f['Notes']->key . "'"],
            'huge help' => ["UPDATE _tiffin_fields SET help = hex(zeroblob(1000)) WHERE key = '" . $this->f['Notes']->key . "'"],
            'text position' => ["UPDATE _tiffin_fields SET position = hex(zeroblob(100)) WHERE key = '" . $this->f['Notes']->key . "'"],
            'too many forms' => ['WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10001)'
                . " INSERT INTO _tiffin_forms (key, table_key, name, format, html, created_at, updated_at) SELECT 'f' || i, '" . $this->rooms->key . "', 'F', 1, '', '" . self::START . "', '" . self::START . "' FROM n"],
        ];
        foreach ($cases as $label => $statements) {
            self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusal($this->hostileArchive(...$statements)), $label);
        }
        $fields = [];
        for ($i = 0; $i < 500; $i++) {
            $key = strtr(sprintf('b%011d', $i), '0123456789', 'abcdefghij');
            $fields[] = "INSERT INTO _tiffin_fields (key, table_key, sql_name, name, type, options, position, created_at, updated_at) VALUES ('" . $key . "', '" . $this->rooms->key . "', NULL, 'F" . $i . "', 'attachment', '{}', 9, '" . self::START . "', '" . self::START . "')";
        }
        self::assertSame(ArchiveRefused::BAD_METADATA, $this->refusal($this->hostileArchive(...$fields)), 'too many fields in a table');
        self::assertSame(ArchiveRefused::UNEXPECTED_OBJECT, $this->refusal($this->hostileArchive(
            'PRAGMA writable_schema = ON',
            "UPDATE sqlite_master SET sql = sql || '/*' || hex(zeroblob(600000)) || '*/' WHERE name = 'x_" . Identifier::tableName($this->items->key) . "_trash'",
        )), 'huge schema text');
    }

    /**
     * Checks and rebuilds $db in a separate process with memory_limit LIMIT.
     *
     * @return array<string, mixed>
     */
    private function probe(string $db): array
    {
        $small = $this->export();
        $job = $this->jobFolder();
        $command = [
            PHP_BINARY, '-d', 'memory_limit=' . self::LIMIT,
            __DIR__ . '/memory-probe.php', RepoFiles::path('vendor/autoload.php'), $small, $job, $db, $job . '/rebuilt',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(0, $status, 'The probe failed: ' . trim($out . ' ' . $err));
        $result = json_decode(trim($out), true);
        self::assertIsArray($result, 'The probe printed no result: ' . trim($out . ' ' . $err));

        return $result;
    }
}
