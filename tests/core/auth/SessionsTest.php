<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUTH-5: server-side sessions. */
#[CoversClass(Sessions::class)]
#[CoversClass(SessionRecord::class)]
#[CoversClass(IssuedSession::class)]
final class SessionsTest extends TestCase
{
    private TempDir $temp;
    private FixedClock $clock;
    private SystemDatabase $system;
    private Users $users;
    private Sessions $sessions;
    private int $member;
    private int $other;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-06-01T12:00:00Z');
        $random = new SeededRandom('tiffindb-sessions-test-seed-1');
        $this->system = SystemDatabase::create(new DataDirectory($this->temp->path(), 'data'), $this->clock, $random);
        $this->users = new Users($this->system, new Keys($random), $this->clock);
        $this->sessions = new Sessions($this->system, $this->clock, $random);
        [$this->member, $this->other] = $this->system->db()->write(fn (): array => [
            $this->users->create('member', 'Example Member', Role::Member, 'x', false)->id,
            $this->users->create('member2', 'Another Member', Role::Member, 'x', false)->id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function start(int $userId): IssuedSession
    {
        return $this->system->db()->write(fn (): IssuedSession => $this->sessions->start($userId));
    }

    private function resolve(string $token): ?SessionRecord
    {
        return $this->system->db()->write(fn (): ?SessionRecord => $this->sessions->resolve($token));
    }

    /** @return array<string, mixed> */
    private function row(string $token): ?array
    {
        return $this->system->db()->one('SELECT * FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    public function testStartIssuesA43CharacterTokenAndStoresOnlyItsHash(): void
    {
        $issued = $this->start($this->member);
        $this->assertSame(43, strlen($issued->token));
        $this->assertMatchesRegularExpression(Sessions::TOKEN_PATTERN, $issued->token);
        $this->assertSame(hash('sha256', $issued->token), $issued->record->tokenHash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $issued->record->csrfToken);
        $this->assertSame($this->member, $issued->record->userId);
        $this->assertSame('2026-06-01T12:00:00Z', $issued->record->createdAt);
        $this->assertSame('2026-07-01T12:00:00Z', $issued->record->absoluteExpiresAt);

        $row = $this->row($issued->token);
        $this->assertNotNull($row);
        $this->assertSame('2026-06-08T12:00:00Z', $row['idle_expires_at']);
        $this->assertSame('2026-07-01T12:00:00Z', $row['absolute_expires_at']);

        // The token appears in no column of any table.
        $tables = array_column($this->system->db()->all("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
        foreach ($tables as $table) {
            foreach ($this->system->db()->all('SELECT * FROM "' . $table . '"') as $stored) {
                foreach ($stored as $value) {
                    $this->assertStringNotContainsString($issued->token, (string) $value, 'Token stored in ' . $table);
                }
            }
        }
        $this->assertStringNotContainsString($issued->token, print_r($issued, true));
        $this->assertStringNotContainsString($issued->token, var_export($issued->record, true));
    }

    public function testTokensDiffer(): void
    {
        $a = $this->start($this->member);
        $b = $this->start($this->member);
        $this->assertNotSame($a->token, $b->token);
        $this->assertNotSame($a->record->csrfToken, $b->record->csrfToken);
    }

    public function testResolve(): void
    {
        $issued = $this->start($this->member);
        $resolved = $this->resolve($issued->token);
        $this->assertNotNull($resolved);
        $this->assertSame($issued->record->tokenHash, $resolved->tokenHash);
        $this->assertSame($issued->record->csrfToken, $resolved->csrfToken);
        $this->assertNull($this->resolve(strrev($issued->token)));
        $this->assertNull($this->resolve(''));
        $this->assertNull($this->resolve($issued->token . 'x'));
        $this->assertNull($this->resolve(str_repeat('!', 43)));
    }

    public function testTouchAt299SecondsDoesNothingAndAt301MovesTheIdleExpiry(): void
    {
        $issued = $this->start($this->member);
        $this->clock->advance(299);
        $this->assertNotNull($this->resolve($issued->token));
        $this->assertSame('2026-06-01T12:00:00Z', $this->row($issued->token)['last_seen_at']);
        $this->assertSame('2026-06-08T12:00:00Z', $this->row($issued->token)['idle_expires_at']);

        $this->clock->advance(2);
        $this->assertNotNull($this->resolve($issued->token));
        $this->assertSame('2026-06-01T12:05:01Z', $this->row($issued->token)['last_seen_at']);
        $this->assertSame('2026-06-08T12:05:01Z', $this->row($issued->token)['idle_expires_at']);
    }

    public function testIdleExpiryAfterSevenDays(): void
    {
        $issued = $this->start($this->member);
        $this->clock->advance(Sessions::IDLE_SECONDS - 1);
        $this->assertNotNull($this->resolve($issued->token));
        $kept = $this->start($this->member);
        $this->clock->advance(Sessions::IDLE_SECONDS);
        $this->assertNull($this->resolve($kept->token));
        $this->assertNull($this->row($kept->token), 'The expired row is deleted.');
    }

    public function testAbsoluteExpiryAfterThirtyDaysEvenWhenUsedDaily(): void
    {
        $issued = $this->start($this->member);
        for ($day = 1; $day <= 29; $day++) {
            $this->clock->advance(86400);
            $this->assertNotNull($this->resolve($issued->token), 'Day ' . $day);
            $idle = (string) $this->row($issued->token)['idle_expires_at'];
            $this->assertLessThanOrEqual(0, strcmp($idle, '2026-07-01T12:00:00Z'), 'The idle expiry never passes the absolute one.');
        }
        $this->assertSame('2026-07-01T12:00:00Z', $this->row($issued->token)['idle_expires_at']);
        $this->clock->advance(86400 - 1);
        $this->assertNotNull($this->resolve($issued->token));
        $this->clock->advance(1);
        $this->assertNull($this->resolve($issued->token));
        $this->assertNull($this->row($issued->token));
    }

    public function testAnInactivePersonsSessionResolvesToNullAndIsDeleted(): void
    {
        $issued = $this->start($this->member);
        $this->system->db()->write(fn () => $this->users->setActive($this->member, false));
        $this->assertNull($this->resolve($issued->token));
        $this->assertNull($this->row($issued->token));
    }

    public function testStartDeletesExpiredSessionsOfAnyone(): void
    {
        $old = $this->start($this->other);
        $this->clock->advance(Sessions::IDLE_SECONDS + 1);
        $this->start($this->member);
        $this->assertNull($this->row($old->token));
    }

    public function testEndAndEndAllFor(): void
    {
        $a = $this->start($this->member);
        $b = $this->start($this->member);
        $c = $this->start($this->other);
        $this->system->db()->write(fn () => $this->sessions->end($a->token));
        $this->assertNull($this->resolve($a->token));
        $this->assertNotNull($this->resolve($b->token));
        $this->system->db()->write(fn () => $this->sessions->endAllFor($this->member));
        $this->assertNull($this->resolve($b->token));
        $this->assertNotNull($this->resolve($c->token));
        $this->system->db()->write(fn () => $this->sessions->end('not a token'));
    }

    public function testFlashIsReadOnce(): void
    {
        $issued = $this->start($this->member);
        $take = fn (): ?array => $this->system->db()->write(fn (): ?array => $this->sessions->takeFlash($issued->token));
        $this->assertNull($take());
        $this->system->db()->write(fn () => $this->sessions->setFlash($issued->token, 'notice.saved', ['name' => 'Recipes', 'count' => 2]));
        $this->assertSame(['key' => 'notice.saved', 'params' => ['name' => 'Recipes', 'count' => 2]], $take());
        $this->assertNull($take());
        $this->system->db()->write(fn () => $this->sessions->setFlash($issued->token, 'notice.saved'));
        $this->assertSame(['key' => 'notice.saved', 'params' => []], $take());
    }

    public function testFlashRefusesBadKeysAndParameters(): void
    {
        $issued = $this->start($this->member);
        foreach ([['Not A Key', []], ['notice.saved', ['x' => ['nested']]], ['notice.saved', [1 => 'positional']]] as [$key, $params]) {
            try {
                $this->system->db()->write(fn () => $this->sessions->setFlash($issued->token, $key, $params));
                $this->fail('Accepted ' . $key);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMethodsThatMayWriteNeedTheCallersTransaction(): void
    {
        $issued = $this->start($this->member);
        $calls = [
            fn () => $this->sessions->start($this->member),
            fn () => $this->sessions->resolve($issued->token),
            fn () => $this->sessions->end($issued->token),
            fn () => $this->sessions->endAllFor($this->member),
            fn () => $this->sessions->setFlash($issued->token, 'notice.saved'),
            fn () => $this->sessions->takeFlash($issued->token),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail('Call ' . $i . ' ran outside a transaction.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
