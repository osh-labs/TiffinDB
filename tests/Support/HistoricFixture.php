<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemSchema;

/**
 * A data folder as an earlier TiffinDB left it (M7 spec part 1 section
 * 12.3, ADR 0021 decision 6), built from the documented SQL only: no
 * binary fixture is stored in the repository.
 *
 * The system database is `SystemSchema::STEPS[1]` (version 1), and for
 * version 2 also `STEPS[2]`, with the meta rows of an installed instance,
 * an Admin "Example Admin" (`admin`) and a Member "Example Member"
 * (`member`), both with the synthetic password PASSWORD (bcrypt cost 4),
 * one registry row for "Home Inventory" and the Member's grant to it.
 *
 * The project file at version 1 is the first SQL block of
 * `docs/formats/tiffin.md` section 4 (the version 1 tables, the "before"
 * state of section 11.1), the user-table statements of section 5, and the
 * join table of section 7, with the document's synthetic keys; for
 * versions 2, 3, and 4 the SQL blocks of sections 11.1, 11.2, and 11.3 are
 * then run verbatim, each in its own transaction (they set `PRAGMA
 * user_version` and meta `format_version` themselves). Its data: Items
 * (Name, the title; Value, currency USD; Room, a relation; Receipt, an
 * attachment) with "Toaster" and "Mower" live and "Old kettle" in the
 * trash; Rooms (Name; Items) with "Kitchen" and "Study"; links Toaster and
 * Old kettle to Kitchen, Mower to Study; and one attachment row, Toaster's
 * receipt `receipt-toaster.pdf`, with its file under
 * `attachments/<2>/<64>`.
 *
 * Keys of people and the project, the installation key, and the HMAC key
 * come from a SeededRandom with SEED; times are TIME. All data is synthetic.
 */
final class HistoricFixture
{
    public const SEED = 'tiffindb-historic-fixture-seed-1';
    public const PASSWORD = 'correct horse 1';
    public const TIME = '2026-11-03T09:00:00Z';
    public const PROJECT_NAME = 'Home Inventory';
    public const CREATED_WITH = '0.1.0-dev';

    public const ITEMS = 'h3kq7vn2xbma';
    public const ROOMS = 'p6wz2kq7nm3v';
    public const RELATION = 'c2vb6nxq4tkm';
    /** Items fields by name. */
    public const ITEM_FIELDS = ['Name' => 'm4pz7k2qsw3n', 'Value' => 'b7xk2mq4nz3p', 'Room' => 'r5vk3pn7qx2m', 'Receipt' => 'r6nb3vk2qxza'];
    /** Rooms fields by name. */
    public const ROOM_FIELDS = ['Name' => 'k7n2qz4mxp3w', 'Items' => 'n3xq7mz2kw4p'];
    /** Record ids by title, in insertion order. */
    public const ITEM_IDS = ['Toaster' => 1, 'Mower' => 2, 'Old kettle' => 3];
    public const ROOM_IDS = ['Kitchen' => 1, 'Study' => 2];
    public const RECEIPT = 'receipt-toaster.pdf';

    /** The headings of the format document's upgrade sections, by the version each brings a file to. */
    private const UPGRADE_SECTIONS = [
        2 => '### 11.1 Upgrade from version 1 to version 2',
        3 => '### 11.2 Upgrade from version 2 to version 3',
        4 => '### 11.3 Upgrade from version 3 to version 4',
    ];

    /**
     * @param array<string, string> $userKeys username => key
     * @param array<string, int> $userIds username => id
     */
    private function __construct(
        public readonly DataDirectory $data,
        public readonly string $projectKey,
        public readonly array $userKeys,
        public readonly array $userIds,
        public readonly string $receiptBytes,
    ) {
    }

    /**
     * Builds a data folder at $dataPath (an existing, empty folder) with
     * the system database at $systemVersion (1 or 2) and the project file
     * at $projectVersion (1 to 4).
     */
    public static function build(string $dataPath, int $systemVersion, int $projectVersion): self
    {
        $random = new SeededRandom(self::SEED);
        $keys = new Keys($random);
        $userKeys = ['admin' => $keys->generate(), 'member' => $keys->generate()];
        $projectKey = $keys->generate();
        $installationKey = $keys->generate();
        $hmacKey = bin2hex($random->bytes(32));

        $data = new DataDirectory($dataPath, DataDirectory::IN_TREE);
        $userIds = self::writeSystem($data->systemDatabase(), $systemVersion, $userKeys, $projectKey, $installationKey, $hmacKey);
        $folder = $data->projectFolder($projectKey);
        if (!mkdir($folder, 0750, true)) {
            throw new \RuntimeException('Cannot make the project folder.');
        }
        $receipt = self::writeProject($folder . '/project.tiffin', $projectKey, $projectVersion);

        return new self($data, $projectKey, $userKeys, $userIds, $receipt);
    }

    public function projectFolder(): string
    {
        return $this->data->projectFolder($this->projectKey);
    }

    public function projectFile(): string
    {
        return $this->projectFolder() . '/project.tiffin';
    }

    /** The actor as Authenticator would build it from a session of $username (admin or member). */
    public function actor(string $username): Actor
    {
        return $username === 'admin'
            ? new Actor($this->userIds['admin'], $this->userKeys['admin'], Role::Admin, 'Example Admin')
            : new Actor($this->userIds['member'], $this->userKeys['member'], Role::Member, 'Example Member');
    }

    /** The URL path of a record of Items. */
    public function recordPath(string $title): string
    {
        return '/projects/' . $this->projectKey . '/tables/' . self::ITEMS . '/records/' . self::ITEM_IDS[$title];
    }

    /**
     * Marks the system database as written by a newer TiffinDB: meta
     * `schema_version` 3 and a table that version would have added.
     */
    public function makeSystemNewer(): void
    {
        $db = Sqlite::open($this->data->systemDatabase());
        $db->write(static function (Database $db): void {
            $db->run('CREATE TABLE future_example (id INTEGER PRIMARY KEY, note TEXT NOT NULL)');
            $db->run("UPDATE meta SET value = '3' WHERE key = 'schema_version'");
        });
        $db->close();
    }

    /**
     * Marks the project file as written by a newer TiffinDB: format
     * version 5 (`PRAGMA user_version` and meta `format_version`) and a
     * table that version would have added.
     */
    public function makeProjectNewer(): void
    {
        $db = Sqlite::open($this->projectFile());
        $db->write(static function (Database $db): void {
            $db->run('CREATE TABLE "_tiffin_future_example" ("key" TEXT PRIMARY KEY) WITHOUT ROWID');
            $db->run('PRAGMA user_version = 5');
            $db->run('UPDATE "_tiffin_meta" SET "value" = \'5\' WHERE "key" = \'format_version\'');
        });
        $db->close();
    }

    /**
     * Every row of every table of a database file, by table name (tables
     * in name order, rows in rowid or key order), and its schema objects;
     * read without the TiffinDB connection settings.
     *
     * @return array<string, mixed>
     */
    public static function dump(string $file): array
    {
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $content = ['_schema' => $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll()];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $rows = $pdo->query('SELECT * FROM "' . str_replace('"', '""', (string) $table) . '"')->fetchAll();
            usort($rows, static fn (array $a, array $b): int => serialize($a) <=> serialize($b));
            $content[(string) $table] = $rows;
        }
        $content['_user_version'] = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        $pdo = null;

        return $content;
    }

    /** `PRAGMA user_version` of a file, read without the TiffinDB connection settings. */
    public static function userVersion(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file);
        $version = (int) $pdo->query('PRAGMA user_version')?->fetchColumn();
        $pdo = null;

        return $version;
    }

    /** Meta `schema_version` of a system database file. */
    public static function systemVersion(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file);
        $version = (int) $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'")?->fetchColumn();
        $pdo = null;

        return $version;
    }

    /**
     * @param array<string, string> $userKeys
     * @return array<string, int> username => id
     */
    private static function writeSystem(string $file, int $version, array $userKeys, string $projectKey, string $installationKey, string $hmacKey): array
    {
        if ($version < 1 || $version > 2) {
            throw new \InvalidArgumentException('System versions 1 and 2 are built.');
        }
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $db = Sqlite::create($file);
        $ids = [];
        try {
            $db->write(static function (Database $db) use ($version, $userKeys, $projectKey, $installationKey, $hmacKey, $hash, &$ids): void {
                for ($step = 1; $step <= $version; $step++) {
                    foreach (SystemSchema::STEPS[$step] as $statement) {
                        $db->run($statement);
                    }
                }
                $meta = [
                    'schema_version' => (string) $version,
                    'installation_key' => $installationKey,
                    'hmac_key' => $hmacKey,
                    'created_at' => self::TIME,
                    'installed_at' => self::TIME,
                    'installed_with' => self::CREATED_WITH,
                ];
                foreach ($meta as $key => $value) {
                    $db->run('INSERT INTO meta (key, value) VALUES (?, ?)', [$key, $value]);
                }
                $people = [['admin', 'Example Admin', 'admin'], ['member', 'Example Member', 'member']];
                foreach ($people as [$username, $displayName, $role]) {
                    $db->run(
                        'INSERT INTO users (key, username, username_norm, display_name, role, password_hash, must_change_password, active, created_at, updated_at, password_changed_at)'
                        . ' VALUES (?, ?, ?, ?, ?, ?, 0, 1, ?, ?, ?)',
                        [$userKeys[$username], $username, $username, $displayName, $role, $hash, self::TIME, self::TIME, self::TIME],
                    );
                    $ids[$username] = $db->lastInsertId();
                    $db->run('INSERT INTO user_settings (user_id) VALUES (?)', [$ids[$username]]);
                }
                $db->run(
                    "INSERT INTO projects (key, name, state, created_at, created_by) VALUES (?, ?, 'active', ?, ?)",
                    [$projectKey, self::PROJECT_NAME, self::TIME, $ids['admin']],
                );
                $projectId = $db->lastInsertId();
                $db->run(
                    "INSERT INTO project_grants (project_id, user_id, level, granted_at, granted_by) VALUES (?, ?, 'edit', ?, ?)",
                    [$projectId, $ids['member'], self::TIME, $ids['admin']],
                );
            });
        } finally {
            $db->close();
        }

        return $ids;
    }

    /** Writes the project file; returns the receipt's bytes. */
    private static function writeProject(string $file, string $projectKey, int $version): string
    {
        if ($version < 1 || $version > 4) {
            throw new \InvalidArgumentException('Project format versions 1 to 4 are built.');
        }
        $ddl = self::statements(self::sqlBlocks('## 4. Metadata tables', '## 5. User tables')[0]);
        $userDdl = self::statements(self::sqlBlocks('## 5. User tables', '## 6. Field types')[0]);
        $joinDdl = self::statements(self::sqlBlocks('## 7. Relations', '## 8. Attachments')[0]);
        if (count($ddl) !== 10 || count($userDdl) !== 2 || count($joinDdl) !== 2) {
            throw new \LogicException('docs/formats/tiffin.md sections 4, 5, and 7 do not hold the version 1 statements.');
        }
        $receipt = M4Fixture::receipt(self::RECEIPT);
        $sha = hash('sha256', $receipt);
        $folder = dirname($file) . '/attachments/' . substr($sha, 0, 2);
        if (!mkdir($folder, 0750, true) || file_put_contents($folder . '/' . $sha, $receipt) !== strlen($receipt)) {
            throw new \RuntimeException('Cannot write the attachment file.');
        }

        $db = Sqlite::create($file);
        try {
            $db->run('PRAGMA application_id = 1413891138');
            $db->run('PRAGMA user_version = 1');
            $db->write(static function (Database $db) use ($ddl, $userDdl, $joinDdl, $projectKey, $receipt, $sha): void {
                foreach ($ddl as $statement) {
                    $db->run($statement);
                }
                $meta = ['format' => 'tiffindb-project', 'format_version' => '1', 'project_key' => $projectKey, 'project_name' => self::PROJECT_NAME,
                    'created_at' => self::TIME, 'created_by' => 'm2kq7vn3xbpa', 'created_with' => self::CREATED_WITH, 'updated_at' => self::TIME, 'updated_by' => 'm2kq7vn3xbpa'];
                foreach ($meta as $key => $value) {
                    $db->run('INSERT INTO "_tiffin_meta" ("key", "value") VALUES (?, ?)', [$key, $value]);
                }
                // Items: the documented user table; Rooms: the same shape.
                foreach ($userDdl as $statement) {
                    $db->run($statement);
                    $db->run(strtr($statement, [self::ITEMS => self::ROOMS]));
                }
                $item = self::ITEM_FIELDS;
                $room = self::ROOM_FIELDS;
                $db->run('ALTER TABLE "t_' . self::ITEMS . '" ADD COLUMN "f_' . $item['Name'] . '" TEXT');
                $db->run('ALTER TABLE "t_' . self::ITEMS . '" ADD COLUMN "f_' . $item['Value'] . '" INTEGER');
                $db->run('ALTER TABLE "t_' . self::ROOMS . '" ADD COLUMN "f_' . $room['Name'] . '" TEXT');
                foreach ($joinDdl as $statement) {
                    $db->run($statement);
                }
                // Each Item is in one Room (section 7: left_max one).
                $db->run('CREATE UNIQUE INDEX "x_j_' . self::RELATION . '_l1" ON "j_' . self::RELATION . '" ("left_id")');
                foreach ([[self::ITEMS, 'Items', 'item', 1, $item['Name']], [self::ROOMS, 'Rooms', 'room', 2, $room['Name']]] as [$key, $name, $itemName, $position, $title]) {
                    $db->run('INSERT INTO "_tiffin_tables" VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$key, 't_' . $key, $name, $itemName, $position, $title, self::TIME, self::TIME]);
                }
                $relation = '{"relation":"' . self::RELATION . '","side":"%s"}';
                $fields = [
                    [$item['Name'], self::ITEMS, 'f_' . $item['Name'], 'Name', 'text', '{"max_length":500}', 1],
                    [$item['Value'], self::ITEMS, 'f_' . $item['Value'], 'Value', 'currency', '{"code":"USD","minor_digits":2}', 2],
                    [$item['Room'], self::ITEMS, null, 'Room', 'relation', sprintf($relation, 'left'), 3],
                    [$item['Receipt'], self::ITEMS, null, 'Receipt', 'attachment', '{}', 4],
                    [$room['Name'], self::ROOMS, 'f_' . $room['Name'], 'Name', 'text', '{"max_length":500}', 1],
                    [$room['Items'], self::ROOMS, null, 'Items', 'relation', sprintf($relation, 'right'), 2],
                ];
                foreach ($fields as [$key, $table, $sqlName, $name, $type, $options, $position]) {
                    $db->run(
                        'INSERT INTO "_tiffin_fields" ("key", "table_key", "sql_name", "name", "type", "options", "position", "created_at", "updated_at") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$key, $table, $sqlName, $name, $type, $options, $position, self::TIME, self::TIME],
                    );
                }
                $db->run(
                    'INSERT INTO "_tiffin_relations" VALUES (?, ?, ?, ?, \'one\', ?, ?, \'many\', ?)',
                    [self::RELATION, 'j_' . self::RELATION, self::ITEMS, $item['Room'], self::ROOMS, $room['Items'], self::TIME],
                );
                foreach (array_keys(self::ROOM_IDS) as $name) {
                    $db->run('INSERT INTO "t_' . self::ROOMS . '" ("_created_at", "_updated_at", "f_' . $room['Name'] . '") VALUES (?, ?, ?)', [self::TIME, self::TIME, $name]);
                }
                foreach ([['Toaster', 2999, null], ['Mower', 32900, null], ['Old kettle', 1500, self::TIME]] as [$name, $value, $trashed]) {
                    $db->run(
                        'INSERT INTO "t_' . self::ITEMS . '" ("_created_at", "_updated_at", "_trashed_at", "f_' . $item['Name'] . '", "f_' . $item['Value'] . '") VALUES (?, ?, ?, ?, ?)',
                        [self::TIME, self::TIME, $trashed, $name, $value],
                    );
                }
                $links = [[self::ITEM_IDS['Toaster'], self::ROOM_IDS['Kitchen']], [self::ITEM_IDS['Mower'], self::ROOM_IDS['Study']], [self::ITEM_IDS['Old kettle'], self::ROOM_IDS['Kitchen']]];
                foreach ($links as [$left, $right]) {
                    $db->run('INSERT INTO "j_' . self::RELATION . '" ("left_id", "right_id", "position") VALUES (?, ?, 0)', [$left, $right]);
                }
                $db->run(
                    'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at", "created_by") VALUES (?, ?, \'application/pdf\', ?, ?, ?)',
                    [$sha, strlen($receipt), self::RECEIPT, self::TIME, 'm2kq7vn3xbpa'],
                );
                $db->run(
                    'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, 1)',
                    [$item['Receipt'], self::ITEM_IDS['Toaster'], $db->lastInsertId()],
                );
            });
            for ($target = 2; $target <= $version; $target++) {
                $statements = self::statements(self::sqlBlocks(self::UPGRADE_SECTIONS[$target])[0]);
                $db->write(static function (Database $db) use ($statements): void {
                    foreach ($statements as $statement) {
                        $db->run($statement);
                    }
                });
            }
        } finally {
            $db->close();
        }

        return $receipt;
    }

    /** @return list<string> the statements of an SQL block, without their semicolons */
    private static function statements(string $block): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $block)), static fn (string $s): bool => $s !== ''));
    }

    /**
     * The SQL blocks of docs/formats/tiffin.md between the heading $from
     * and $to, or the next heading of any level when $to is null.
     *
     * @return list<string>
     */
    private static function sqlBlocks(string $from, ?string $to = null): array
    {
        $doc = RepoFiles::read('docs/formats/tiffin.md');
        $start = strpos($doc, $from);
        if ($start === false) {
            throw new \LogicException('Heading not found in the format document: ' . $from);
        }
        $end = $to === null ? strpos($doc, "\n#", $start + strlen($from)) : strpos($doc, $to, $start);
        if ($end === false) {
            throw new \LogicException('End of section not found in the format document.');
        }
        preg_match_all('/```sql\n(.*?)```/s', substr($doc, $start, $end - $start), $blocks);
        if ($blocks[1] === []) {
            throw new \LogicException('No SQL block in the format document section: ' . $from);
        }

        return $blocks[1];
    }
}
