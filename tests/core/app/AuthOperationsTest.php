<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\AuthOperations;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-31 and the rest of AuthOperations. */
#[CoversClass(AuthOperations::class)]
final class AuthOperationsTest extends AppTestCase
{
    private const NEW = 'a new horse 22';

    public function testWrongCurrentPasswordIsRefusedAndCounted(): void
    {
        $session = $this->fx->signIn($this->ops, 'member');
        $auth = $this->ops->auth();

        self::assertSame(
            [['current', 'problem.password.current_wrong', []], ['password', 'problem.password.too_short', ['min' => 10]]],
            self::problems(fn () => $auth->changeOwnPassword($this->member, $session->token(), 'wrong horse 1', 'short')),
        );
        self::assertSame(1, $this->failures('member'));
        // A right current password with a weak new one is no failure.
        self::assertSame(
            [['password', 'problem.password.too_short', ['min' => 10]]],
            self::problems(fn () => $auth->changeOwnPassword($this->member, $session->token(), CoreFixture::PASSWORD, 'short one')),
        );
        self::assertSame(1, $this->failures('member'));
        self::assertNotNull($auth->resume($session->token()));
        self::assertNotContains('auth.password_changed', $this->auditActions());
    }

    public function testFiveWrongAttemptsThrottle(): void
    {
        $session = $this->fx->signIn($this->ops, 'member');
        $auth = $this->ops->auth();
        for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
            self::assertSame([['current', 'problem.password.current_wrong', []]], self::problems(fn () => $auth->changeOwnPassword($this->member, $session->token(), 'wrong horse ' . $i, self::NEW)));
        }
        // Blocked: even the right password is not checked.
        self::assertSame([['current', 'problem.throttled', ['seconds' => 30]]], self::problems(fn () => $auth->changeOwnPassword($this->member, $session->token(), CoreFixture::PASSWORD, self::NEW)));
        // The block is shared with sign-in.
        self::assertSame(SignInOutcome::Throttled, $auth->signIn('member', CoreFixture::PASSWORD, '192.0.2.40')->outcome);

        $this->fx->clock->advance(31);
        $issued = $auth->changeOwnPassword($this->member, $session->token(), CoreFixture::PASSWORD, self::NEW);
        self::assertNotNull($auth->resume($issued->token()));
        self::assertSame(0, $this->failures('member'));
    }

    public function testSuccessEndsOtherSessionsAndClearsMustChange(): void
    {
        $this->fx->system->db()->write(fn () => $this->fx->users->setPassword($this->member->userId, $this->fx->hasher->hash(CoreFixture::PASSWORD), true));
        $first = $this->fx->signIn($this->ops, 'member');
        $second = $this->fx->signIn($this->ops, 'member');
        $other = $this->fx->signIn($this->ops, 'member2');

        $issued = $this->ops->auth()->changeOwnPassword($this->member, $first->token(), CoreFixture::PASSWORD, self::NEW);

        self::assertNull($this->ops->auth()->resume($first->token()));
        self::assertNull($this->ops->auth()->resume($second->token()));
        self::assertNotNull($this->ops->auth()->resume($other->token()));
        $resumed = $this->ops->auth()->resume($issued->token());
        self::assertNotNull($resumed);
        self::assertSame($this->member->userId, $resumed->actor->userId);
        self::assertFalse($resumed->mustChangePassword);
        self::assertFalse($this->fx->person('member')->mustChangePassword);
        self::assertSame(SignInOutcome::Invalid, $this->ops->auth()->signIn('member', CoreFixture::PASSWORD, '192.0.2.41')->outcome);
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('member', self::NEW, '192.0.2.41')->outcome);
        $entry = $this->lastAudit('auth.password_changed');
        self::assertSame([$this->member->userId, 'user', $this->member->userKey], [$entry->actorUserId, $entry->targetKind, $entry->targetKey]);
    }

    public function testTheTokenMustBeTheActorsOwnLiveSession(): void
    {
        $mine = $this->fx->signIn($this->ops, 'member');
        $theirs = $this->fx->signIn($this->ops, 'member2');
        $auth = $this->ops->auth();

        $this->assertThrows(AccessDenied::class, fn () => $auth->changeOwnPassword($this->member, $theirs->token(), CoreFixture::PASSWORD, self::NEW));
        $this->assertThrows(AccessDenied::class, fn () => $auth->changeOwnPassword($this->member, 'not-a-token', CoreFixture::PASSWORD, self::NEW));
        $this->fx->system->db()->write(fn () => $this->fx->users->setActive($this->member->userId, false));
        $this->assertThrows(AccessDenied::class, fn () => $auth->changeOwnPassword($this->member, $mine->token(), CoreFixture::PASSWORD, self::NEW));
        self::assertSame(0, $this->failures('member'));
    }

    public function testSignOutIsRecordedOnce(): void
    {
        $session = $this->fx->signIn($this->ops, 'member');
        $this->ops->auth()->signOut($session->token());
        $this->ops->auth()->signOut($session->token());

        self::assertNull($this->ops->auth()->resume($session->token()));
        self::assertSame(['auth.sign_in', 'auth.sign_out'], $this->auditActions());
    }

    public function testFlashIsCarriedToTheNextPageOnce(): void
    {
        $session = $this->fx->signIn($this->ops, 'member');
        $auth = $this->ops->auth();
        self::assertNull($auth->takeFlash($session->token()));

        $auth->setFlash($session->token(), 'notice.project.created', ['name' => 'Example Garden']);

        self::assertSame(['key' => 'notice.project.created', 'params' => ['name' => 'Example Garden']], $auth->takeFlash($session->token()));
        self::assertNull($auth->takeFlash($session->token()));
        self::assertFalse($this->fx->system->db()->inTransaction());
    }

    public function testCanAndRequireLookTheProjectUp(): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $this->ops->projects()->setSharing($this->admin, $project->key, [$this->member->userKey]);
        $auth = $this->ops->auth();

        self::assertTrue($auth->can($this->admin, Permission::ManagePeople));
        self::assertFalse($auth->can($this->member, Permission::ManagePeople));
        self::assertTrue($auth->can($this->member, Permission::EditRecords, $project->key));
        self::assertFalse($auth->can($this->member, Permission::ChangeSchema, $project->key));
        self::assertFalse($auth->can($this->member2, Permission::ViewProject, $project->key));
        self::assertFalse($auth->can($this->admin, Permission::ViewProject, 'zzzzzzzzzzzz'));
        self::assertTrue($auth->can($this->admin, Permission::ChangeSchema, $project->key));

        $auth->require($this->member, Permission::ViewProject, $project->key);
        $this->assertThrows(AccessDenied::class, fn () => $auth->require($this->member, Permission::ChangeSchema, $project->key));
        $this->assertThrows(NotFound::class, fn () => $auth->require($this->member2, Permission::ViewProject, $project->key));
        $this->assertThrows(NotFound::class, fn () => $auth->require($this->admin, Permission::ViewProject, 'zzzzzzzzzzzz'));
        $this->assertThrows(AccessDenied::class, fn () => $auth->require($this->member, Permission::ViewAudit));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $auth->can($this->admin, Permission::ViewProject));
        self::assertSame(Role::Admin, $this->admin->role);
    }

    private function failures(string $username): int
    {
        $bucket = LoginThrottle::userBucket($this->fx->system, $username);

        return (int) $this->fx->system->db()->value('SELECT failures FROM login_throttle WHERE bucket = ?', [$bucket]);
    }
}
