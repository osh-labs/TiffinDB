<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use Closure;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * A project file in a temporary folder for schema and trash tests, with a
 * fixed clock and seeded keys (seed given by each test class). Records are
 * inserted with plain SQL, since record operations arrive in M2. All data
 * is synthetic.
 */
trait ProjectFixture
{
    public const PROJECT_KEY = 'q7mzk2wbx4ae';
    public const USER_KEY = 'm2kq7vn3xbpa';
    public const OTHER_USER_KEY = 'w4zt6mq2nk7c';
    public const START = '2026-11-12T03:15:00Z';

    private TempDir $temp;
    private FixedClock $clock;
    private Keys $keys;
    private Snapshots $snapshots;
    private FieldTypes $types;
    private Database $db;
    private string $folder;
    /** @var list<SnapshotInfo> */
    private array $taken = [];
    /** @var list<Database> */
    private array $extraConnections = [];

    private function setUpProject(string $seed): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock(self::START);
        $this->keys = new Keys(new SeededRandom($seed));
        $this->snapshots = new Snapshots($this->clock, new Keys(new SeededRandom($seed . '-snapshots')));
        $this->types = FieldTypes::standard(new Keys(new SeededRandom($seed . '-choices')));
        $this->folder = $this->temp->path('projects/' . self::PROJECT_KEY);
        mkdir($this->folder, 0750, true);
        $this->db = ProjectFile::create($this->path(), self::PROJECT_KEY, 'Example Project', self::USER_KEY, '0.2.0-dev', $this->clock);
    }

    private function tearDownProject(): void
    {
        foreach ($this->extraConnections as $connection) {
            $connection->close();
        }
        $this->db->close();
        $this->temp->remove();
    }

    private function path(): string
    {
        return $this->folder . '/project.tiffin';
    }

    /** @param (Closure(SnapshotInfo): void)|null $onSnapshot also called after recording each snapshot */
    private function editor(?string $userKey = null, ?Closure $onSnapshot = null): SchemaEditor
    {
        return new SchemaEditor(
            $this->db,
            $this->folder,
            $this->types,
            $this->keys,
            $this->clock,
            $this->snapshots,
            $userKey ?? self::USER_KEY,
            function (SnapshotInfo $info) use ($onSnapshot): void {
                $this->taken[] = $info;
                if ($onSnapshot !== null) {
                    $onSnapshot($info);
                }
            },
        );
    }

    private function schema(): Schema
    {
        return new Schema($this->db, $this->types);
    }

    /**
     * Inserts a record with stored values by field key; returns its id.
     *
     * @param array<string, mixed> $values
     */
    private function insert(string $tableKey, array $values = [], bool $trashed = false): int
    {
        $columns = '"_created_at", "_updated_at", "_trashed_at"';
        $placeholders = '?, ?, ?';
        $params = [self::START, self::START, $trashed ? self::START : null];
        foreach ($values as $fieldKey => $value) {
            $columns .= ', ' . Identifier::column($fieldKey);
            $placeholders .= ', ?';
            $params[] = $value;
        }
        $this->db->run('INSERT INTO ' . Identifier::table($tableKey) . ' (' . $columns . ') VALUES (' . $placeholders . ')', $params);

        return $this->db->lastInsertId();
    }

    private function link(string $relationKey, int $leftId, int $rightId): void
    {
        $this->db->run('INSERT INTO ' . Identifier::joinTable($relationKey) . ' ("left_id", "right_id") VALUES (?, ?)', [$leftId, $rightId]);
    }

    private function stored(string $tableKey, string $fieldKey, int $id): mixed
    {
        return $this->db->value('SELECT ' . Identifier::column($fieldKey) . ' FROM ' . Identifier::table($tableKey) . ' WHERE "id" = ?', [$id]);
    }

    /** @return list<mixed> stored values of a column in id order */
    private function column(string $tableKey, string $fieldKey): array
    {
        return array_map(
            static fn (array $row): mixed => $row['v'],
            $this->db->all('SELECT ' . Identifier::column($fieldKey) . ' AS "v" FROM ' . Identifier::table($tableKey) . ' ORDER BY "id"'),
        );
    }

    /** @return array<string, string> name => sql of every object in sqlite_master */
    private function masterNames(): array
    {
        $names = [];
        foreach ($this->db->all('SELECT "name", "sql" FROM "sqlite_master"') as $row) {
            $names[$row['name']] = (string) $row['sql'];
        }

        return $names;
    }

    /** @return array<string, mixed> */
    private function fieldRow(string $fieldKey): array
    {
        $row = $this->db->one('SELECT * FROM "_tiffin_fields" WHERE "key" = ?', [$fieldKey]);
        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array{0: ?string, 1: string, 2: array<string, string|int>}> */
    private static function problemsOf(ValidationFailed $failed): array
    {
        return array_map(static fn (Problem $p): array => [$p->field, $p->key, $p->params], $failed->problems);
    }

    /**
     * The problems $work throws, as [field, key, params]; fails when it does not throw ValidationFailed.
     *
     * @return list<array{0: ?string, 1: string, 2: array<string, string|int>}>
     */
    private static function problems(callable $work): array
    {
        try {
            $work();
        } catch (ValidationFailed $failed) {
            return self::problemsOf($failed);
        }
        self::fail('ValidationFailed was not thrown.');
    }

    /** @return list<string> snapshot file names on disk */
    private function snapshotFiles(): array
    {
        return array_map(static fn (SnapshotInfo $i): string => $i->file, $this->snapshots->list($this->folder));
    }

    /** A second connection to the same file, closed in tearDown. */
    private function secondConnection(): Database
    {
        $other = ProjectFile::open($this->path(), self::PROJECT_KEY, $this->snapshots);
        $this->extraConnections[] = $other;

        return $other;
    }
}
