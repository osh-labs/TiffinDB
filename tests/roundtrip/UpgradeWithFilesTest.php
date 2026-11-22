<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-8 (M4 spec part 1 section 12.8): a version 3 project file built
 * from the documented blocks of docs/formats/tiffin.md (sections 4 and 5,
 * M4Fixture::buildVersionThree) with attachment rows and their files on
 * disk, put in place of a project's file. Opened through the core it is
 * upgraded to version 4 after exactly one `format-upgrade` snapshot (which
 * holds the version 3 file), keeps its records, rows, and links, and
 * serves its files: the live records' files with their bytes and types,
 * the trashed record's file not at all. A second open takes no snapshot,
 * and the upgraded file opens with the plain PDO SQLite driver.
 */
#[CoversNothing]
final class UpgradeWithFilesTest extends RoundTripCase
{
    public function testAVersionThreeProjectWithFilesUpgradesAndServesThem(): void
    {
        $key = $this->ops->projects()->create($this->admin, 'Home Inventory', null)->key;
        $this->ops->projects()->setSharing($this->admin, $key, [$this->core->person('member')->key]);
        $this->ops->close();
        $folder = $this->core->projectFolder($key);
        $file = $folder . '/project.tiffin';
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($file . $suffix)) {
                self::assertTrue(unlink($file . $suffix));
            }
        }
        $rows = M4Fixture::buildVersionThree($file, $key, RepoFiles::root());
        self::assertSame(3, self::userVersion($file));
        $before = self::dump($file);
        self::assertSame([], self::snapshots($folder));

        $ops = $this->core->operations();
        $member = $this->core->actor('member');
        $listing = $ops->records()->listing($member, $key, M4Fixture::V3_ITEMS, ListRequest::none());
        self::assertSame(2, $listing->total);
        self::assertSame(4, self::userVersion($file), 'Upgraded to version 4.');
        $snapshots = self::snapshots($folder);
        self::assertCount(1, $snapshots);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z-format-upgrade-[a-z2-7]{4}\.tiffin\z/', $snapshots[0]);
        self::assertSame(3, self::userVersion($folder . '/snapshots/' . $snapshots[0]), 'The snapshot holds the version 3 file.');

        $after = self::dump($file);
        foreach (['t_' . M4Fixture::V3_ITEMS, '_tiffin_attachments', '_tiffin_attachment_links', '_tiffin_fields', '_tiffin_tables'] as $table) {
            self::assertSame($before[$table], $after[$table], "{$table} is unchanged by the upgrade.");
        }
        self::assertSame([], $after['_tiffin_collections']);

        foreach ($rows as $name => $row) {
            if ($name === 'kettle.gif') {
                try {
                    $ops->attachments()->serve($member, $key, (string) $row['id'], false);
                    self::fail('A file linked only from a record in the trash was served.');
                } catch (NotFound) {
                    $this->addToAssertionCount(1);
                }
                continue;
            }
            $served = $ops->attachments()->serve($member, $key, (string) $row['id'], false);
            $inline = $row['type'] !== 'application/pdf';
            self::assertSame([$inline ? $row['type'] : 'application/octet-stream', $name, strlen($row['bytes']), $inline], [$served->mediaType, $served->fileName, $served->size, $served->inline], $name);
            self::assertSame($row['bytes'], (string) file_get_contents($served->path), $name);
        }

        // A second open: no snapshot; the plain driver reads the upgraded file.
        $ops->close();
        $again = $this->core->operations();
        self::assertSame(2, $again->records()->listing($member, $key, M4Fixture::V3_ITEMS, ListRequest::none())->total);
        self::assertCount(1, self::snapshots($folder));
        $again->close();
        $pdo = new \PDO('sqlite:' . $file);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '\\_tiffin\\_%' ESCAPE '\\' ORDER BY name")?->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['_tiffin_attachment_links', '_tiffin_attachments', '_tiffin_collections', '_tiffin_fields', '_tiffin_forms', '_tiffin_import_log', '_tiffin_meta', '_tiffin_relations', '_tiffin_tables'], $tables);
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')?->fetchColumn());
        $pdo = null;
        self::assertSame('4', Sqlite::openUntrusted($file)->value('SELECT value FROM _tiffin_meta WHERE key = \'format_version\''));
    }

    private static function userVersion(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file);
        $version = (int) $pdo->query('PRAGMA user_version')?->fetchColumn();
        $pdo = null;

        return $version;
    }

    /** @return list<string> */
    private static function snapshots(string $folder): array
    {
        if (!is_dir($folder . '/snapshots')) {
            return [];
        }

        return array_values(array_filter((array) scandir($folder . '/snapshots'), static fn ($name): bool => is_string($name) && str_ends_with($name, '.tiffin')));
    }
}
