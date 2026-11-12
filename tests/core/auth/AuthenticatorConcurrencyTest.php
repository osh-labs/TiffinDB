<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Authenticator;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-AUTH-7 (concurrency, P68): eight processes guess one person's password
 * at the same moment, each on its own connection to one system database.
 * Exactly five are counted and checked; the other three are throttled
 * without a check; an unrelated writer is never refused as busy.
 *
 * The children are local PHP processes started with PHP_BINARY; nothing
 * leaves the machine. The outcome does not depend on timing: step (a) of
 * every sign-in is serialised by SQLite, so the first five to commit are
 * counted and the rest see the block.
 */
#[CoversClass(Authenticator::class)]
final class AuthenticatorConcurrencyTest extends TestCase
{
    private const CHILDREN = 8;
    private const TIMEOUT_SECONDS = 60;

    private const CHILD = <<<'PHP'
        <?php
        declare(strict_types=1);

        use TiffinDB\Core\Auth\Authenticator;
        use TiffinDB\Core\Auth\LoginThrottle;
        use TiffinDB\Core\Auth\PasswordHasher;
        use TiffinDB\Core\Auth\Sessions;
        use TiffinDB\Core\Auth\Users;
        use TiffinDB\Core\Storage\DataDirectory;
        use TiffinDB\Core\Storage\Keys;
        use TiffinDB\Core\Storage\SecureRandom;
        use TiffinDB\Core\Storage\SystemClock;
        use TiffinDB\Core\System\AuditLog;
        use TiffinDB\Core\System\SystemDatabase;

        [, $autoload, $dataPath, $index, $username, $password, $address] = $argv;
        require $autoload;
        $system = SystemDatabase::open(new DataDirectory($dataPath, 'data'));
        $clock = new SystemClock();
        $random = new SecureRandom();
        $auth = new Authenticator(
            $system,
            new Users($system, new Keys($random), $clock),
            new Sessions($system, $clock, $random),
            new LoginThrottle($system, $clock),
            new PasswordHasher(['bcrypt' => 10]),
            new AuditLog($system, $clock),
        );
        file_put_contents($dataPath . '/ready-' . $index, '1');
        $deadline = microtime(true) + 30;
        while (!is_file($dataPath . '/go') && microtime(true) < $deadline) {
            usleep(1000);
        }
        echo $auth->signIn($username, $password, $address)->outcome->name;
        PHP;

    private TempDir $temp;
    private SystemDatabase $system;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available, so no child processes can be started.');
        }
        $this->temp = TempDir::create();
        $clock = new FixedClock('2026-06-01T12:00:00Z');
        $random = new SeededRandom('tiffindb-authenticator-concurrency-seed-1');
        mkdir($this->temp->path('data'), 0750);
        $this->system = SystemDatabase::create(new DataDirectory($this->temp->path('data'), 'data'), $clock, $random);
        $users = new Users($this->system, new Keys($random), $clock);
        // Cost 10 makes each verification take long enough for the children to overlap.
        $hash = (new PasswordHasher(['bcrypt' => 10]))->hash('correct horse 1');
        $this->system->db()->write(fn () => $users->create('admin', 'Example Admin', Role::Admin, $hash, false));
    }

    protected function tearDown(): void
    {
        if (isset($this->system)) {
            $this->system->db()->close();
            $this->temp->remove();
        }
    }

    public function testParallelGuessesAreAllCountedAndNoneSlipsThrough(): void
    {
        $dataPath = $this->temp->path('data');
        $script = $this->temp->path('child.php');
        file_put_contents($script, self::CHILD);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $children = [];
        for ($i = 0; $i < self::CHILDREN; $i++) {
            $command = [PHP_BINARY, $script, $autoload, $dataPath, (string) $i, 'admin', 'wrong horse ' . $i, '192.0.2.' . (10 + $i)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $children[] = [$process, $pipes];
        }

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        while (count(glob($dataPath . '/ready-*')) < self::CHILDREN && microtime(true) < $deadline) {
            usleep(1000);
        }
        $this->assertCount(self::CHILDREN, glob($dataPath . '/ready-*'), 'Every child is ready.');
        file_put_contents($dataPath . '/go', '1');

        // An unrelated signed-in request keeps writing while the guesses are checked.
        $writer = Sqlite::open($this->system->db()->path(), 5000);
        $busy = 0;
        $writes = 0;
        try {
            while ($this->running($children) && microtime(true) < $deadline) {
                try {
                    $writer->write(static fn (Database $db) => $db->run("UPDATE meta SET value = value WHERE key = 'created_at'"));
                    $writes++;
                } catch (Busy) {
                    $busy++;
                }
                usleep(2000);
            }
        } finally {
            $writer->close();
        }

        $outcomes = [];
        foreach ($children as [$process, $pipes]) {
            $outcomes[] = trim((string) stream_get_contents($pipes[1]));
            $errors = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), 'A child failed: ' . $errors);
        }
        sort($outcomes);
        $this->assertSame(
            ['Invalid', 'Invalid', 'Invalid', 'Invalid', 'Invalid', 'Throttled', 'Throttled', 'Throttled'],
            $outcomes,
        );
        $userBucket = LoginThrottle::userBucket($this->system, 'admin');
        $this->assertSame(5, $this->system->db()->value('SELECT failures FROM login_throttle WHERE bucket = ?', [$userBucket]));
        $this->assertSame(0, $busy, 'The unrelated writer was refused as busy.');
        $this->assertGreaterThan(0, $writes);
    }

    /** @param list<array{0: resource, 1: array<int, resource>}> $children */
    private function running(array $children): bool
    {
        foreach ($children as [$process]) {
            if (proc_get_status($process)['running']) {
                return true;
            }
        }

        return false;
    }
}
