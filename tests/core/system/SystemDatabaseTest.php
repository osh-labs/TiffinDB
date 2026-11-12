<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Core\System\SystemSchema;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-SYS-1 and T-SYS-2: creating and opening the system database. */
#[CoversClass(SystemDatabase::class)]
#[CoversClass(SystemSchema::class)]
final class SystemDatabaseTest extends TestCase
{
    private const SEED = 'tiffindb-system-database-test-seed-1';

    private TempDir $temp;
    private DataDirectory $data;
    /** @var list<Database> */
    private array $open = [];

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->data = new DataDirectory($this->temp->path(), 'data');
    }

    protected function tearDown(): void
    {
        foreach ($this->open as $db) {
            $db->close();
        }
        $this->temp->remove();
    }

    private function create(): SystemDatabase
    {
        $system = SystemDatabase::create($this->data, new FixedClock('2026-03-14T08:00:00Z'), new SeededRandom(self::SEED));
        $this->open[] = $system->db();

        return $system;
    }

    public function testCreateMakesExactlyTheTablesAndIndexesOfSection51(): void
    {
        $system = $this->create();
        $rows = $system->db()->all(
            "SELECT type, name, tbl_name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name",
        );
        $this->assertSame([
            ['type' => 'index', 'name' => 'audit_log_action', 'tbl_name' => 'audit_log'],
            ['type' => 'index', 'name' => 'audit_log_at', 'tbl_name' => 'audit_log'],
            ['type' => 'index', 'name' => 'project_grants_user', 'tbl_name' => 'project_grants'],
            ['type' => 'index', 'name' => 'projects_name', 'tbl_name' => 'projects'],
            ['type' => 'index', 'name' => 'sessions_user', 'tbl_name' => 'sessions'],
            ['type' => 'table', 'name' => 'audit_log', 'tbl_name' => 'audit_log'],
            ['type' => 'table', 'name' => 'login_throttle', 'tbl_name' => 'login_throttle'],
            ['type' => 'table', 'name' => 'meta', 'tbl_name' => 'meta'],
            ['type' => 'table', 'name' => 'project_grants', 'tbl_name' => 'project_grants'],
            ['type' => 'table', 'name' => 'projects', 'tbl_name' => 'projects'],
            ['type' => 'table', 'name' => 'sessions', 'tbl_name' => 'sessions'],
            ['type' => 'table', 'name' => 'user_settings', 'tbl_name' => 'user_settings'],
            ['type' => 'table', 'name' => 'users', 'tbl_name' => 'users'],
        ], $rows);
    }

    public function testColumnsMatchTheDdl(): void
    {
        $db = $this->create()->db();
        $columns = static fn (string $table): array => array_column($db->all('SELECT name FROM pragma_table_info(?)', [$table]), 'name');
        $this->assertSame(['key', 'value'], $columns('meta'));
        $this->assertSame(
            ['id', 'key', 'username', 'username_norm', 'display_name', 'role', 'password_hash', 'must_change_password',
             'active', 'created_at', 'updated_at', 'password_changed_at', 'last_sign_in_at'],
            $columns('users'),
        );
        $this->assertSame(['user_id', 'theme', 'accent', 'typeface', 'text_size', 'line_spacing', 'reduce_motion'], $columns('user_settings'));
        $this->assertSame(
            ['token_hash', 'user_id', 'csrf_token', 'flash', 'created_at', 'last_seen_at', 'idle_expires_at', 'absolute_expires_at'],
            $columns('sessions'),
        );
        $this->assertSame(['bucket', 'failures', 'window_started_at', 'last_failure_at', 'blocked_until'], $columns('login_throttle'));
        $this->assertSame(['id', 'key', 'name', 'state', 'created_at', 'created_by'], $columns('projects'));
        $this->assertSame(['project_id', 'user_id', 'level', 'granted_at', 'granted_by'], $columns('project_grants'));
        $this->assertSame(
            ['id', 'at', 'actor_user_id', 'action', 'project_id', 'target_kind', 'target_key', 'detail'],
            $columns('audit_log'),
        );
        $sql = implode("\n", array_column($db->all("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL"), 'sql'));
        $this->assertStringNotContainsStringIgnoringCase('CHECK', $sql, 'Enumerated values are validated in code (P44).');
    }

    public function testCreateWritesTheMetaKeysOfSection52(): void
    {
        $system = $this->create();
        $meta = [];
        foreach ($system->db()->all('SELECT key, value FROM meta ORDER BY key') as $row) {
            $meta[$row['key']] = $row['value'];
        }
        $this->assertSame(['created_at', 'hmac_key', 'installation_key', 'schema_version'], array_keys($meta));
        $this->assertSame('1', $meta['schema_version']);
        $this->assertTrue(Keys::isValid($meta['installation_key']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $meta['hmac_key']);
        $this->assertSame('2026-03-14T08:00:00Z', $meta['created_at']);
        $this->assertFalse($system->isInstalled());
        $this->assertSame('wal', $system->db()->journalMode());
        $this->assertSame(1, $system->db()->value('PRAGMA foreign_keys'));
    }

    public function testIsInstalledOnceInstalledAtIsSet(): void
    {
        $system = $this->create();
        $system->setMeta('installed_at', '2026-03-14T08:05:00Z');
        $this->assertTrue($system->isInstalled());
        $this->assertSame('2026-03-14T08:05:00Z', $system->meta('installed_at'));
        $this->assertNull($system->meta('installed_with'));
    }

    public function testSetMetaRefusesTheManagedKeys(): void
    {
        $system = $this->create();
        foreach (['hmac_key', 'schema_version'] as $key) {
            try {
                $system->setMeta($key, '0');
                $this->fail('setMeta accepted ' . $key);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHmacIsKeyedAndSeparatesPurposes(): void
    {
        $system = $this->create();
        $key = (string) hex2bin((string) $system->meta('hmac_key'));
        $this->assertSame(hash_hmac('sha256', "throttle-user\0admin", $key), $system->hmac('throttle-user', 'admin'));
        $this->assertNotSame($system->hmac('throttle-user', 'admin'), $system->hmac('throttle-address', 'admin'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $system->hmac('x', ''));
    }

    public function testCreateRefusesAnExistingFile(): void
    {
        $this->create();
        $this->expectException(\RuntimeException::class);
        SystemDatabase::create($this->data, new FixedClock(), new SeededRandom(self::SEED));
    }

    public function testOpenReadsWhatCreateWrote(): void
    {
        $created = $this->create();
        $installationKey = $created->meta('installation_key');
        $created->db()->close();
        $opened = SystemDatabase::open($this->data);
        $this->open[] = $opened->db();
        $this->assertSame($installationKey, $opened->meta('installation_key'));
        $this->assertSame(1, SystemSchema::currentVersion($opened->db()));
    }

    public function testOpenOfAMissingFileIsUnavailableMissing(): void
    {
        try {
            SystemDatabase::open($this->data);
            $this->fail('No exception.');
        } catch (Unavailable $error) {
            $this->assertSame(Unavailable::MISSING, $error->reason);
        }
    }

    public function testOpenOfANewerVersionIsUnavailableNewer(): void
    {
        $system = $this->create();
        $system->db()->run("UPDATE meta SET value = '99' WHERE key = 'schema_version'");
        $system->db()->close();
        try {
            SystemDatabase::open($this->data);
            $this->fail('No exception.');
        } catch (Unavailable $error) {
            $this->assertSame(Unavailable::NEWER, $error->reason);
        }
    }

    public function testOpenOfAnotherSqliteFileIsNotAccepted(): void
    {
        $other = Sqlite::create($this->data->systemDatabase());
        $other->run('CREATE TABLE notes (id INTEGER PRIMARY KEY)');
        $other->close();
        try {
            SystemDatabase::open($this->data);
            $this->fail('No exception.');
        } catch (Unavailable $error) {
            $this->assertSame(Unavailable::NOT_PROJECT, $error->reason);
        }
    }

    public function testRequireTransaction(): void
    {
        $system = $this->create();
        $system->db()->write(function () use ($system): void {
            $system->requireTransaction();
            $this->addToAssertionCount(1);
        });
        $this->expectException(LogicException::class);
        $system->requireTransaction();
    }
}
