<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Authenticator;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\Auth\SignInResult;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUTH-7: sign-in, resume, and sign-out. */
#[CoversClass(Authenticator::class)]
#[CoversClass(SignInResult::class)]
#[CoversClass(SignedIn::class)]
#[CoversClass(SignInOutcome::class)]
final class AuthenticatorTest extends TestCase
{
    private const PASSWORD = 'correct horse 1';
    private const ADDRESS = '192.0.2.10';

    private TempDir $temp;
    private FixedClock $clock;
    private SystemDatabase $system;
    private Users $users;
    private Sessions $sessions;
    private PasswordHasher $hasher;
    private Authenticator $auth;
    private UserRecord $admin;
    private UserRecord $member;
    private UserRecord $inactive;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-06-01T12:00:00Z');
        $random = new SeededRandom('tiffindb-authenticator-test-seed-1');
        $this->system = SystemDatabase::create(new DataDirectory($this->temp->path(), 'data'), $this->clock, $random);
        $this->users = new Users($this->system, new Keys($random), $this->clock);
        $this->sessions = new Sessions($this->system, $this->clock, $random);
        $this->hasher = new PasswordHasher(['bcrypt' => 4]);
        $this->auth = new Authenticator(
            $this->system,
            $this->users,
            $this->sessions,
            new LoginThrottle($this->system, $this->clock),
            $this->hasher,
            new AuditLog($this->system, $this->clock),
        );
        $hash = $this->hasher->hash(self::PASSWORD);
        [$this->admin, $this->member, $this->inactive] = $this->system->db()->write(function () use ($hash): array {
            $inactive = $this->users->create('member2', 'Another Member', Role::Member, $hash, false);
            $this->users->setActive($inactive->id, false);

            return [
                $this->users->create('admin', 'Example Admin', Role::Admin, $hash, false),
                $this->users->create('member', 'Example Member', Role::Member, $hash, true),
                $inactive,
            ];
        });
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(): array
    {
        return $this->system->db()->all('SELECT actor_user_id, action, project_id, target_kind, target_key, detail FROM audit_log ORDER BY id');
    }

    private function sessionCount(): int
    {
        return (int) $this->system->db()->value('SELECT COUNT(*) FROM sessions');
    }

    public function testSuccess(): void
    {
        $result = $this->auth->signIn('Admin', self::PASSWORD, self::ADDRESS);
        $this->assertSame(SignInOutcome::Success, $result->outcome);
        $this->assertNotNull($result->session);
        $this->assertFalse($result->mustChangePassword);
        $this->assertSame(0, $result->waitSeconds);
        $this->assertSame($this->admin->id, $result->session->record->userId);
        $this->assertSame('2026-06-01T12:00:00Z', $this->users->byId($this->admin->id)?->lastSignInAt);
        $this->assertSame([[
            'actor_user_id' => $this->admin->id, 'action' => AuditAction::SIGN_IN, 'project_id' => null,
            'target_kind' => 'user', 'target_key' => $this->admin->key, 'detail' => '{}',
        ]], $this->auditRows());

        $member = $this->auth->signIn('member', self::PASSWORD, self::ADDRESS);
        $this->assertSame(SignInOutcome::Success, $member->outcome);
        $this->assertTrue($member->mustChangePassword);
    }

    public function testEverySignInStartsANewSession(): void
    {
        $first = $this->auth->signIn('admin', self::PASSWORD, self::ADDRESS);
        $second = $this->auth->signIn('admin', self::PASSWORD, self::ADDRESS);
        $this->assertNotSame($first->session?->token, $second->session?->token);
        $this->assertSame(2, $this->sessionCount());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failures(): iterable
    {
        yield 'wrong password' => ['admin', 'wrong horse 1', AuditAction::REASON_BAD_PASSWORD];
        yield 'unknown user' => ['nobody', self::PASSWORD, AuditAction::REASON_UNKNOWN_USER];
        yield 'inactive user with the right password' => ['member2', self::PASSWORD, AuditAction::REASON_INACTIVE];
        yield 'not a valid username' => ['no such person!', self::PASSWORD, AuditAction::REASON_UNKNOWN_USER];
    }

    #[DataProvider('failures')]
    public function testFailuresGiveIdenticalResultsAndOneVerification(string $username, string $password, string $reason): void
    {
        $verifications = $this->hasher->verifyCalls();
        $hashes = $this->hasher->hashCalls();
        $result = $this->auth->signIn($username, $password, self::ADDRESS);

        $this->assertEquals(new SignInResult(SignInOutcome::Invalid), $result);
        $this->assertSame(1, $this->hasher->verifyCalls() - $verifications, 'Exactly one password verification.');
        $this->assertSame(0, $this->hasher->hashCalls() - $hashes, 'No hashing.');
        $this->assertSame(0, $this->sessionCount());

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $this->assertSame(AuditAction::SIGN_IN_FAILED, $rows[0]['action']);
        $this->assertSame('{"reason":"' . $reason . '"}', $rows[0]['detail']);
        $person = $this->users->byUsername($username);
        $this->assertSame($person?->id, $rows[0]['actor_user_id']);
        $this->assertSame($person?->key, $rows[0]['target_key']);
        $this->assertSame($person === null ? null : 'user', $rows[0]['target_kind']);
        $this->assertNoSecretsIn($username, $password);
    }

    public function testFailuresCountOnTheUserAndAddressBuckets(): void
    {
        $this->auth->signIn('Admin ', 'wrong horse 1', self::ADDRESS);
        $userBucket = LoginThrottle::userBucket($this->system, 'admin');
        $addressBucket = LoginThrottle::addressBucket($this->system, self::ADDRESS);
        $rows = $this->system->db()->all('SELECT bucket, failures FROM login_throttle ORDER BY bucket');
        $this->assertSame([
            ['bucket' => $addressBucket, 'failures' => 1],
            ['bucket' => $userBucket, 'failures' => 1],
        ], $rows);

        $this->auth->signIn('admin', self::PASSWORD, self::ADDRESS);
        $this->assertSame([['bucket' => $addressBucket, 'failures' => 1]], $this->system->db()->all('SELECT bucket, failures FROM login_throttle'));
    }

    public function testThrottledSignInVerifiesNothing(): void
    {
        for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
            $this->assertSame(SignInOutcome::Invalid, $this->auth->signIn('admin', 'wrong horse 1', self::ADDRESS)->outcome);
        }
        $verifications = $this->hasher->verifyCalls();
        $result = $this->auth->signIn('admin', self::PASSWORD, '198.51.100.7');
        $this->assertSame(SignInOutcome::Throttled, $result->outcome);
        $this->assertSame(30, $result->waitSeconds);
        $this->assertNull($result->session);
        $this->assertSame($verifications, $this->hasher->verifyCalls(), 'The password was not checked.');
        $this->assertSame(0, $this->sessionCount());

        $last = $this->auditRows()[LoginThrottle::USER_LIMIT];
        $this->assertSame('{"reason":"throttled"}', $last['detail']);
        $this->assertSame($this->admin->id, $last['actor_user_id']);

        $this->clock->advance(30);
        $this->assertSame(SignInOutcome::Success, $this->auth->signIn('admin', self::PASSWORD, '198.51.100.7')->outcome);
    }

    public function testThrottlingAppliesToUnknownUsernamesToo(): void
    {
        for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
            $this->auth->signIn('nobody', 'wrong horse 1', '198.51.100.' . $i);
        }
        $result = $this->auth->signIn('NOBODY', 'wrong horse 1', '198.51.100.99');
        $this->assertSame(SignInOutcome::Throttled, $result->outcome);
        $this->assertNull($this->auditRows()[LoginThrottle::USER_LIMIT]['actor_user_id']);
    }

    public function testTheAddressBucketThrottlesEveryUsername(): void
    {
        for ($i = 0; $i < LoginThrottle::ADDRESS_LIMIT; $i++) {
            $this->auth->signIn('guess' . $i, 'wrong horse 1', self::ADDRESS);
        }
        $this->assertSame(SignInOutcome::Throttled, $this->auth->signIn('admin', self::PASSWORD, self::ADDRESS)->outcome);
        $this->assertSame(SignInOutcome::Success, $this->auth->signIn('admin', self::PASSWORD, '198.51.100.7')->outcome);
    }

    public function testRehashOnSignIn(): void
    {
        $strongerAuth = new Authenticator(
            $this->system,
            $this->users,
            $this->sessions,
            new LoginThrottle($this->system, $this->clock),
            $stronger = new PasswordHasher(['bcrypt' => 5]),
            new AuditLog($this->system, $this->clock),
        );
        $before = $this->system->db()->one('SELECT password_hash, password_changed_at FROM users WHERE id = ?', [$this->admin->id]);
        $this->clock->advance(60);
        $this->assertSame(SignInOutcome::Success, $strongerAuth->signIn('admin', self::PASSWORD, self::ADDRESS)->outcome);
        $after = $this->system->db()->one('SELECT password_hash, password_changed_at FROM users WHERE id = ?', [$this->admin->id]);
        $this->assertNotSame($before['password_hash'], $after['password_hash']);
        $this->assertStringStartsWith('$2y$05$', (string) $after['password_hash']);
        $this->assertSame($before['password_changed_at'], $after['password_changed_at'], 'A rehash is not a password change.');
        $this->assertTrue($stronger->verify(self::PASSWORD, (string) $after['password_hash']));

        $hashes = $this->hasher->hashCalls();
        $this->assertSame(SignInOutcome::Success, $this->auth->signIn('member', self::PASSWORD, self::ADDRESS)->outcome);
        $this->assertSame($hashes, $this->hasher->hashCalls(), 'No rehash when the parameters are current.');
    }

    public function testResume(): void
    {
        $issued = $this->auth->signIn('member', self::PASSWORD, self::ADDRESS)->session;
        $this->assertNotNull($issued);
        $signedIn = $this->auth->resume($issued->token);
        $this->assertNotNull($signedIn);
        $this->assertEquals(new Actor($this->member->id, $this->member->key, Role::Member, 'Example Member'), $signedIn->actor);
        $this->assertSame($issued->record->csrfToken, $signedIn->session->csrfToken);
        $this->assertTrue($signedIn->mustChangePassword);

        $this->assertNull($this->auth->resume(''));
        $this->assertNull($this->auth->resume('not-a-token'));
        $this->assertNull($this->auth->resume(str_repeat('A', 43)));

        $this->system->db()->write(fn () => $this->users->setActive($this->member->id, false));
        $this->assertNull($this->auth->resume($issued->token));
        $this->assertSame(0, $this->sessionCount());
    }

    public function testResumeAfterAPrivilegeChangeEndsWithTheSessions(): void
    {
        $issued = $this->auth->signIn('member', self::PASSWORD, self::ADDRESS)->session;
        $this->assertNotNull($issued);
        // What an operation does when it changes a role: change and end sessions in one transaction.
        $this->system->db()->write(function (): void {
            $this->users->update($this->member->id, 'member', 'Example Member', Role::Admin);
            $this->sessions->endAllFor($this->member->id);
        });
        $this->assertNull($this->auth->resume($issued->token));
    }

    public function testSignOut(): void
    {
        $issued = $this->auth->signIn('admin', self::PASSWORD, self::ADDRESS)->session;
        $this->assertNotNull($issued);
        $this->auth->signOut($issued->token);
        $this->assertNull($this->auth->resume($issued->token));
        $this->auth->signOut($issued->token);
        $this->auth->signOut('garbage');
        $actions = array_column($this->auditRows(), 'action');
        $this->assertSame([AuditAction::SIGN_IN, AuditAction::SIGN_OUT], $actions, 'Only a live session is recorded as signed out.');
        $last = $this->auditRows()[1];
        $this->assertSame($this->admin->id, $last['actor_user_id']);
        $this->assertSame($this->admin->key, $last['target_key']);
        $this->assertNoSecretsIn('admin', self::PASSWORD, $issued->token);
    }

    public function testInactiveAndWrongPasswordForTheSamePersonLookAlike(): void
    {
        $inactive = $this->auth->signIn('member2', self::PASSWORD, self::ADDRESS);
        $wrong = $this->auth->signIn('member2', 'wrong horse 1', '198.51.100.7');
        $unknown = $this->auth->signIn('member3', self::PASSWORD, '198.51.100.8');
        $this->assertEquals($inactive, $wrong);
        $this->assertEquals($inactive, $unknown);
        $this->assertSame($this->inactive->id, $this->auditRows()[0]['actor_user_id']);
    }

    private function assertNoSecretsIn(string ...$secrets): void
    {
        $dump = json_encode($this->system->db()->all('SELECT * FROM audit_log'))
            . json_encode($this->system->db()->all('SELECT * FROM login_throttle'));
        foreach ($secrets as $secret) {
            if ($secret === 'admin' || $secret === 'member2') {
                // Known usernames may appear only as the person's key and id, never as text.
                $this->assertStringNotContainsString('"' . $secret . '"', (string) $dump);

                continue;
            }
            $this->assertStringNotContainsString($secret, (string) $dump);
        }
        $this->assertStringNotContainsString(self::ADDRESS, (string) $dump);
    }
}
