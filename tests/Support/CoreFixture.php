<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\SystemDatabase;

/**
 * An installed TiffinDB instance in a temporary directory (M1 spec
 * section 13): a data directory with a system database, an Admin
 * "Example Admin" (`admin`), and Members "Example Member" (`member`) and
 * "Another Member" (`member2`), all with the synthetic password
 * PASSWORD. Built directly through the repositories, not the installer.
 *
 * Time is a FixedClock; randomness a SeededRandom from the seed given (or
 * DEFAULT_SEED). Passwords are hashed with bcrypt cost 4. Records are
 * added through the core (addRecord, as the Admin) or inserted with plain
 * SQL (insertRecord). All data is synthetic.
 *
 * For M3 (import, export, archive, plugins): addPerson and setActive for
 * more people, upload() for a file as PHP would leave an upload, and
 * pluginsRoot() for a temporary copy of `plugins/` with three synthetic
 * third-party plugins (one valid, one needing a missing PHP extension, one
 * with a broken manifest).
 */
final class CoreFixture
{
    public const DEFAULT_SEED = 'tiffindb-core-fixture-seed-1';
    public const PASSWORD = 'correct horse 1';
    public const START = '2026-03-02T10:00:00Z';
    public const VERSION = '0.2.0-dev';
    public const ADDRESS = '192.0.2.10';

    public readonly TempDir $temp;
    public readonly FixedClock $clock;
    public readonly Random $random;
    public readonly PasswordHasher $hasher;
    public readonly DataDirectory $data;
    public readonly SystemDatabase $system;
    public readonly Users $users;
    /** @var list<Operations> */
    private array $operations = [];
    /** @var list<Database> */
    private array $connections = [];
    /** @var list<SystemDatabase> */
    private array $systems = [];
    private ?Operations $recordWriter = null;
    private int $uploads = 0;

    /** Synthetic third-party plugins pluginsRoot() adds: '<kind>/<id>' => manifest JSON text. */
    public const EXTRA_PLUGINS = [
        'import/example' => '{"api": 1, "id": "example", "kind": "import", "name": "Example format", "version": "1.0.0",'
            . ' "class": "TiffinDB\\\\Plugin\\\\Import\\\\Example\\\\ExampleImporter", "licence": "MIT", "extensions": ["exm"],'
            . ' "requires_extensions": [], "description": "A synthetic plugin for tests."}',
        'import/needs' => '{"api": 1, "id": "needs", "kind": "import", "name": "Needs an extension", "version": "1.0.0",'
            . ' "class": "TiffinDB\\\\Plugin\\\\Import\\\\Needs\\\\NeedsImporter", "licence": "MIT", "extensions": ["nds"],'
            . ' "requires_extensions": ["tiffindbmissingext"], "description": ""}',
        'export/broken' => '{"api": 1}',
    ];

    public function __construct(string $seed = self::DEFAULT_SEED)
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock(self::START);
        $this->random = new SeededRandom($seed);
        $this->hasher = new PasswordHasher(['bcrypt' => 4]);
        mkdir($this->temp->path('data'), 0750);
        $this->data = new DataDirectory($this->temp->path('data'), DataDirectory::IN_TREE);
        $this->system = SystemDatabase::create($this->data, $this->clock, $this->random);
        $this->users = new Users($this->system, new Keys(new SeededRandom($seed . '-people')), $this->clock);
        $hash = $this->hasher->hash(self::PASSWORD);
        $this->system->db()->write(function () use ($hash): void {
            $this->users->create('admin', 'Example Admin', Role::Admin, $hash, false);
            $this->users->create('member', 'Example Member', Role::Member, $hash, false);
            $this->users->create('member2', 'Another Member', Role::Member, $hash, false);
            $this->system->setMeta(SystemDatabase::META_INSTALLED_AT, Timestamp::format($this->clock->now()));
            $this->system->setMeta(SystemDatabase::META_INSTALLED_WITH, self::VERSION);
        });
    }

    /**
     * Core operations over this instance. With $random null they share the
     * fixture's SeededRandom; with $system given (another connection, see
     * openSystem) they use that connection.
     *
     * @param (\Closure(string): void)|null $seam
     */
    public function operations(?Random $random = null, ?SystemDatabase $system = null, ?\Closure $seam = null): Operations
    {
        $operations = new Operations(
            $system ?? $this->system,
            $this->data,
            $this->clock,
            $random ?? $this->random,
            $this->hasher,
            self::VERSION,
            $seam,
        );
        $this->operations[] = $operations;

        return $operations;
    }

    /** A second connection to the system database, closed by remove(). */
    public function openSystem(): SystemDatabase
    {
        $system = SystemDatabase::open($this->data);
        $this->systems[] = $system;

        return $system;
    }

    public function person(string $username): UserRecord
    {
        $person = $this->users->byUsername($username);
        if ($person === null) {
            throw new \LogicException('No such fixture person.');
        }

        return $person;
    }

    /** The actor as Authenticator would build it from a session, read now. */
    public function actor(string $username): Actor
    {
        $person = $this->person($username);

        return new Actor($person->id, $person->key, $person->role, $person->displayName);
    }

    /** Signs in through the core and returns the session. */
    public function signIn(Operations $operations, string $username, string $password = self::PASSWORD): IssuedSession
    {
        $result = $operations->auth()->signIn($username, $password, self::ADDRESS);
        if ($result->outcome !== SignInOutcome::Success || $result->session === null) {
            throw new \LogicException('The fixture sign-in failed.');
        }

        return $result->session;
    }

    public function projectFolder(string $projectKey): string
    {
        return $this->data->projectFolder($projectKey);
    }

    /** A separate connection to a project file, closed by remove(). */
    public function projectDb(string $projectKey): Database
    {
        $db = Sqlite::open($this->projectFolder($projectKey) . '/project.tiffin');
        $this->connections[] = $db;

        return $db;
    }

    /**
     * Inserts a record with stored values by field key; returns its id.
     *
     * @param array<string, mixed> $values
     */
    public function insertRecord(string $projectKey, string $tableKey, array $values = [], bool $trashed = false): int
    {
        $db = $this->projectDb($projectKey);
        $now = Timestamp::format($this->clock->now());
        $columns = [Identifier::system('_created_at'), Identifier::system('_updated_at'), Identifier::system('_trashed_at')];
        $params = [$now, $now, $trashed ? $now : null];
        foreach ($values as $fieldKey => $value) {
            $columns[] = Identifier::column((string) $fieldKey);
            $params[] = $value;
        }
        $db->run(
            'INSERT INTO ' . Identifier::table($tableKey) . ' (' . implode(', ', $columns) . ') VALUES (' . Database::placeholders(count($params)) . ')',
            $params,
        );

        return $db->lastInsertId();
    }

    /**
     * Adds a record through RecordOperations::create acting as the Admin,
     * with form text by field key; returns its id.
     *
     * @param array<string, string> $text
     */
    public function addRecord(string $projectKey, string $tableKey, array $text): int
    {
        $this->recordWriter ??= $this->operations();

        return $this->recordWriter->records()->create($this->actor('admin'), $projectKey, $tableKey, $text);
    }

    /** Adds a person with the fixture password; returns the actor as a session would build it. */
    public function addPerson(string $username, string $displayName, Role $role): Actor
    {
        $hash = $this->hasher->hash(self::PASSWORD);
        $this->system->db()->write(function () use ($username, $displayName, $role, $hash): void {
            $this->users->create($username, $displayName, $role, $hash, false);
        });

        return $this->actor($username);
    }

    /** Marks a person active or inactive directly in the system database. */
    public function setActive(string $username, bool $active): void
    {
        $id = $this->person($username)->id;
        $this->system->db()->write(function () use ($id, $active): void {
            $this->users->setActive($id, $active);
        });
    }

    /** Writes $contents to a new file outside the data directory, as PHP leaves an upload; returns its path. */
    public function upload(string $contents): string
    {
        $folder = $this->temp->path('uploads');
        if (!is_dir($folder)) {
            mkdir($folder, 0750);
        }
        $path = $folder . '/php-upload-' . ++$this->uploads . '.tmp';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * A temporary `plugins/` directory: a copy of the application's own
     * plugins plus EXTRA_PLUGINS (the valid one with a class file that is
     * never loaded by the tests). For scanning only: PluginLoader binds a
     * plugin namespace to one directory per process, so a test that loads
     * a shipped plugin uses the application's own `plugins/`.
     */
    public function pluginsRoot(): string
    {
        $root = $this->temp->path('plugins');
        if (is_dir($root)) {
            return $root;
        }
        self::copyTree(RepoFiles::path('plugins'), $root);
        foreach (self::EXTRA_PLUGINS as $ref => $manifest) {
            $dir = $root . '/' . $ref;
            mkdir($dir . '/src', 0750, true);
            file_put_contents($dir . '/plugin.json', $manifest);
        }
        file_put_contents(
            $root . '/import/example/src/ExampleImporter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Plugin\\Import\\Example;\n\nfinal class ExampleImporter\n{\n}\n",
        );
        file_put_contents(
            $root . '/import/needs/src/NeedsImporter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace TiffinDB\\Plugin\\Import\\Needs;\n\nfinal class NeedsImporter\n{\n}\n",
        );

        return $root;
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0750, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $source = $from . '/' . $name;
            if (is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                self::copyTree($source, $to . '/' . $name);
            } else {
                copy($source, $to . '/' . $name);
            }
        }
    }

    /** Every row of every system-database table, by table name. @return array<string, list<array<string, mixed>>> */
    public function systemRows(): array
    {
        $rows = [];
        foreach ($this->system->db()->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
            $name = (string) $table['name'];
            $rows[$name] = $this->system->db()->all('SELECT * FROM "' . str_replace('"', '""', $name) . '"');
        }

        return $rows;
    }

    public function remove(): void
    {
        foreach ($this->operations as $operations) {
            $operations->close();
        }
        foreach ($this->connections as $db) {
            $db->close();
        }
        foreach ($this->systems as $system) {
            $system->db()->close();
        }
        $this->system->db()->close();
        $this->temp->remove();
    }
}
