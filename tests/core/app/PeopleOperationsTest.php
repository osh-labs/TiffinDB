<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\PeopleOperations;
use TiffinDB\Core\App\PersonSummary;
use TiffinDB\Core\App\ProjectAccess;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Grants;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-30: managing people. */
#[CoversClass(PeopleOperations::class)]
final class PeopleOperationsTest extends AppTestCase
{
    private const TEMPORARY = 'temporary pass 7';

    public function testCreateValidatesEverythingAtOnce(): void
    {
        $people = $this->ops->people();
        self::assertSame(
            [
                ['username', 'problem.username.invalid', []],
                ['display_name', 'problem.name.empty', []],
                ['temporary_password', 'problem.password.too_short', ['min' => 10]],
            ],
            self::problems(fn () => $people->create($this->admin, 'no spaces', ' ', Role::Member, 'short', [])),
        );
        self::assertSame(
            [['username', 'problem.username.taken', []], ['display_name', 'problem.name.too_long', ['max' => 60]]],
            self::problems(fn () => $people->create($this->admin, ' MEMBER ', str_repeat('x', 61), Role::Member, self::TEMPORARY, [])),
        );
        self::assertSame(
            [['temporary_password', 'problem.password.same_as_username', []]],
            self::problems(fn () => $people->create($this->admin, 'example.cook', 'Example Cook', Role::Member, 'EXAMPLE.COOK', [])),
        );
        self::assertCount(3, $this->fx->systemRows()['users']);
    }

    public function testCreateAddsAMemberWithGrantsWhoMustChangePassword(): void
    {
        $garden = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $books = $this->ops->projects()->create($this->admin, 'Example Books', null);

        $person = $this->ops->people()->create($this->admin, ' example.cook ', ' Example Cook ', Role::Member, self::TEMPORARY, [$garden->key, 'zzzzzzzzzzzz', 'bad']);

        self::assertSame(['example.cook', 'Example Cook', Role::Member, true, true], [$person->username, $person->displayName, $person->role, $person->active, $person->mustChangePassword]);
        $access = $this->ops->people()->projectAccess($this->admin, $person->key);
        self::assertContainsOnlyInstancesOf(ProjectAccess::class, $access);
        self::assertSame(['Example Books' => false, 'Example Garden' => true], $this->accessMap($access));
        self::assertSame(['role' => 'member'], $this->lastAudit('people.created')->detail);
        self::assertSame($person->key, $this->lastAudit('people.created')->targetKey);
        $signIn = $this->ops->auth()->signIn('EXAMPLE.COOK', self::TEMPORARY, '192.0.2.20');
        self::assertSame([SignInOutcome::Success, true], [$signIn->outcome, $signIn->mustChangePassword]);

        $admin = $this->ops->people()->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, [$books->key]);
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM project_grants WHERE user_id = ?', [$admin->id]));
        self::assertSame(['Example Books' => true, 'Example Garden' => true], $this->accessMap($this->ops->people()->projectAccess($this->admin, $admin->key)));
    }

    public function testListCountsProjects(): void
    {
        $garden = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $this->ops->projects()->create($this->admin, 'Example Books', null);
        $this->ops->projects()->setSharing($this->admin, $garden->key, [$this->member->userKey]);

        $list = $this->ops->people()->list($this->admin);
        self::assertContainsOnlyInstancesOf(PersonSummary::class, $list);
        $counts = [];
        foreach ($list as $row) {
            $counts[$row->person->displayName] = $row->projectCount;
        }
        self::assertSame(['Another Member' => 0, 'Example Admin' => 2, 'Example Member' => 1], $counts);
        self::assertSame('member', $this->ops->people()->get($this->admin, $this->member->userKey)->username);
        $this->assertThrows(NotFound::class, fn () => $this->ops->people()->get($this->admin, 'zzzzzzzzzzzz'));
    }

    public function testSuggestedPasswordsHaveTheSpecifiedShape(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $password = $this->ops->people()->suggestPassword($this->admin);
            self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{4}(-[abcdefghjkmnpqrstuvwxyz23456789]{4}){2}\z/', $password);
            $seen[$password] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testUpdateChangesNamesAndRoleAndEndsSessionsWhenNeeded(): void
    {
        $people = $this->ops->people();
        $session = $this->fx->signIn($this->ops, 'member');

        $people->update($this->admin, $this->member->userKey, 'member', 'Example Member Renamed', Role::Member);
        self::assertNotNull($this->ops->auth()->resume($session->token()), 'A display name change keeps sessions.');
        self::assertSame(['role' => 'member', 'username_changed' => false, 'name_changed' => true], $this->lastAudit('people.updated')->detail);

        $people->update($this->admin, $this->member->userKey, 'member.renamed', 'Example Member Renamed', Role::Member);
        self::assertNull($this->ops->auth()->resume($session->token()), 'A username change ends sessions.');
        self::assertSame(['role' => 'member', 'username_changed' => true, 'name_changed' => false], $this->lastAudit('people.updated')->detail);

        $session = $this->fx->signIn($this->ops, 'member.renamed');
        $updated = $people->update($this->admin, $this->member->userKey, 'member.renamed', 'Example Member Renamed', Role::Admin);
        self::assertSame(Role::Admin, $updated->role);
        self::assertNull($this->ops->auth()->resume($session->token()), 'A role change ends sessions.');

        self::assertSame(
            [['username', 'problem.username.taken', []], ['display_name', 'problem.name.empty', []]],
            self::problems(fn () => $people->update($this->admin, $this->member2->userKey, 'ADMIN', '', Role::Member)),
        );
        $this->assertThrows(NotFound::class, fn () => $people->update($this->admin, 'zzzzzzzzzzzz', 'someone', 'Someone', Role::Member));
    }

    public function testTheLastActiveAdminCannotBeDemotedOrDeactivated(): void
    {
        $people = $this->ops->people();
        self::assertSame([['role', 'problem.people.last_admin', []]], self::problems(fn () => $people->update($this->admin, $this->admin->userKey, 'admin', 'Example Admin', Role::Member)));
        self::assertSame([[null, 'problem.people.self_deactivate', []]], self::problems(fn () => $people->deactivate($this->admin, $this->admin->userKey)));

        // A second Admin who is inactive does not count.
        $other = $people->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, []);
        $people->deactivate($this->admin, $other->key);
        self::assertSame([['role', 'problem.people.last_admin', []]], self::problems(fn () => $people->update($this->admin, $this->admin->userKey, 'admin', 'Example Admin', Role::Member)));

        // With two active Admins, one may demote the other, and then not the reverse.
        $people->reactivate($this->admin, $other->key);
        $otherActor = new Actor($other->id, $other->key, Role::Admin, $other->displayName);
        $people->update($otherActor, $this->admin->userKey, 'admin', 'Example Admin', Role::Member);
        self::assertSame(1, $this->fx->users->countActiveAdmins());
        // The demoted Admin's old actor is refused, although its role says Admin.
        $this->assertThrows(AccessDenied::class, fn () => $people->update($this->admin, $other->key, 'example.owner', 'Example Owner', Role::Member));
        self::assertSame([['role', 'problem.people.last_admin', []]], self::problems(fn () => $people->update($otherActor, $other->key, 'example.owner', 'Example Owner', Role::Member)));
        self::assertSame(1, $this->fx->users->countActiveAdmins());
    }

    public function testADeactivatedAdminsStaleActorCannotDeactivateAnother(): void
    {
        $other = $this->ops->people()->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, []);
        // The acting Admin is deactivated behind the session's back: their actor can no longer act.
        $this->fx->system->db()->write(fn () => $this->fx->users->setActive($this->admin->userId, false));
        $this->assertThrows(AccessDenied::class, fn () => $this->ops->people()->deactivate($this->admin, $other->key));
        self::assertTrue($this->fx->users->byKey($other->key)?->active);
    }

    public function testTwoAdminsDemotingEachOtherAtOnceLeaveAnActiveAdmin(): void
    {
        $other = $this->ops->people()->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, []);
        $otherActor = new Actor($other->id, $other->key, Role::Admin, $other->displayName);
        $secondSystem = $this->fx->openSystem();
        // Do not wait the full busy timeout for the lock the first request holds.
        $secondSystem->db()->run('PRAGMA busy_timeout = 50');
        $second = $this->fx->operations(new SeededRandom('second'), $secondSystem);

        $outcomes = [];
        $inside = [];
        $first = $this->fx->operations(null, null, function (string $point) use ($second, $otherActor, &$outcomes, &$inside): void {
            $inside[] = [$point, $this->fx->system->db()->inTransaction()];
            if (count($inside) > 1) {
                return;
            }
            // Between the first request's count and its change, the second Admin demotes the first.
            try {
                $second->people()->update($otherActor, $this->admin->userKey, 'admin', 'Example Admin', Role::Member);
                $outcomes[] = 'second demoted the first';
            } catch (Busy) {
                $outcomes[] = 'second busy';
            } catch (ValidationFailed) {
                $outcomes[] = 'second refused';
            }
        });

        $first->people()->update($this->admin, $other->key, 'example.owner', 'Example Owner', Role::Member);

        self::assertSame([['people.last_admin', true]], $inside, 'The seam ran once, inside the write.');
        self::assertSame(['second busy'], $outcomes);
        self::assertSame(1, $this->fx->users->countActiveAdmins());
        self::assertSame(Role::Admin, $this->fx->users->byKey($this->admin->userKey)?->role);

        // The second request, retried after the first committed, is refused.
        $secondSystem->db()->run('PRAGMA busy_timeout = 5000');
        $this->assertThrows(AccessDenied::class, fn () => $second->people()->update($otherActor, $this->admin->userKey, 'admin', 'Example Admin', Role::Member));
        self::assertSame(1, $this->fx->users->countActiveAdmins());
    }

    public function testRepositoriesRefuseChangesOutsideTheOperationsTransaction(): void
    {
        $system = $this->fx->system;
        $keys = new Keys(new SeededRandom('repositories'));
        $users = new Users($system, $keys, $this->fx->clock);
        $grants = new Grants($system, $this->fx->clock);
        $sessions = new Sessions($system, $this->fx->clock, new SeededRandom('sessions'));
        $registry = new ProjectRegistry($system);
        $throttle = new LoginThrottle($system, $this->fx->clock);
        $bucket = LoginThrottle::userBucket($system, 'member');
        $calls = [
            'Users::create' => fn () => $users->create('someone', 'Someone', Role::Member, 'x', true),
            'Users::update' => fn () => $users->update($this->member->userId, 'member', 'X', Role::Member),
            'Users::setPassword' => fn () => $users->setPassword($this->member->userId, 'x', true),
            'Users::setActive' => fn () => $users->setActive($this->member->userId, false),
            'Grants::setProjectsFor' => fn () => $grants->setProjectsFor($this->member->userId, [], 1),
            'Grants::setUsersFor' => fn () => $grants->setUsersFor(1, [], 1),
            'Sessions::start' => fn () => $sessions->start($this->member->userId),
            'Sessions::endAllFor' => fn () => $sessions->endAllFor($this->member->userId),
            'ProjectRegistry::add' => fn () => $registry->add('aaaaaaaaaaaa', 'X', 1, '2026-01-01T00:00:00Z'),
            'LoginThrottle::failure' => fn () => $throttle->failure($bucket, 5),
            'LoginThrottle::wait' => fn () => $throttle->wait([$bucket]),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail($name . ' changed rows outside a transaction.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        // So every change an operation makes runs in a write() the operation opened: shown here for each guarded change.
        $points = [];
        $ops = $this->fx->operations(null, null, function (string $point) use (&$points): void {
            $points[] = [$point, $this->fx->system->db()->inTransaction(), $this->fx->users->countActiveAdmins()];
        });
        $other = $ops->people()->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, []);
        $ops->people()->deactivate($this->admin, $other->key);
        $ops->people()->reactivate($this->admin, $other->key);
        $ops->people()->update($this->admin, $other->key, 'example.owner', 'Example Owner', Role::Member);
        self::assertSame([['people.last_admin', true, 2], ['people.last_admin', true, 2]], $points);
    }

    public function testDeactivateEndsSessionsAndKeepsGrants(): void
    {
        $garden = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $this->ops->projects()->setSharing($this->admin, $garden->key, [$this->member->userKey]);
        $session = $this->fx->signIn($this->ops, 'member');

        $this->ops->people()->deactivate($this->admin, $this->member->userKey);

        self::assertNull($this->ops->auth()->resume($session->token()));
        self::assertSame(0, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->member->userId]));
        self::assertSame(SignInOutcome::Invalid, $this->ops->auth()->signIn('member', CoreFixture::PASSWORD, '192.0.2.30')->outcome);
        self::assertSame(1, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM project_grants WHERE user_id = ?', [$this->member->userId]));
        self::assertSame('people.deactivated', $this->auditActions()[count($this->auditActions()) - 2]);

        $this->ops->people()->reactivate($this->admin, $this->member->userKey);
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('member', CoreFixture::PASSWORD, '192.0.2.30')->outcome);
        self::assertSame($this->member->userKey, $this->lastAudit('people.reactivated')->targetKey);
    }

    public function testSetTemporaryPasswordSetsMustChangeAndEndsSessions(): void
    {
        $memberSession = $this->fx->signIn($this->ops, 'member');
        $adminSession = $this->fx->signIn($this->ops, 'admin');

        self::assertSame([['temporary_password', 'problem.password.too_short', ['min' => 10]]], self::problems(fn () => $this->ops->people()->setTemporaryPassword($this->admin, $this->member->userKey, 'short')));
        self::assertSame(
            [['temporary_password', 'problem.password.too_short', ['min' => 10]], ['temporary_password', 'problem.password.same_as_username', []]],
            self::problems(fn () => $this->ops->people()->setTemporaryPassword($this->admin, $this->member2->userKey, 'MEMBER2')),
        );
        self::assertNotNull($this->ops->auth()->resume($memberSession->token()));

        $this->ops->people()->setTemporaryPassword($this->admin, $this->member->userKey, self::TEMPORARY);
        self::assertNull($this->ops->auth()->resume($memberSession->token()));
        self::assertNotNull($this->ops->auth()->resume($adminSession->token()));
        self::assertTrue($this->fx->person('member')->mustChangePassword);
        $result = $this->ops->auth()->signIn('member', self::TEMPORARY, '192.0.2.31');
        self::assertSame([SignInOutcome::Success, true], [$result->outcome, $result->mustChangePassword]);
        self::assertSame($this->member->userKey, $this->lastAudit('people.password_set')->targetKey);

        // Also for the acting Admin.
        $this->ops->people()->setTemporaryPassword($this->admin, $this->admin->userKey, self::TEMPORARY);
        self::assertNull($this->ops->auth()->resume($adminSession->token()));
    }

    public function testSetProjectAccessIsForMembersOnly(): void
    {
        $garden = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $books = $this->ops->projects()->create($this->admin, 'Example Books', null);
        $people = $this->ops->people();

        $people->setProjectAccess($this->admin, $this->member->userKey, [$garden->key, $books->key, 'zzzzzzzzzzzz']);
        self::assertSame(['Example Books' => true, 'Example Garden' => true], $this->accessMap($people->projectAccess($this->admin, $this->member->userKey)));
        self::assertSame(['added' => 2, 'removed' => 0], $this->lastAudit('people.access_changed')->detail);
        $people->setProjectAccess($this->admin, $this->member->userKey, [$books->key]);
        self::assertSame(['Example Books' => true, 'Example Garden' => false], $this->accessMap($people->projectAccess($this->admin, $this->member->userKey)));
        self::assertSame(['added' => 0, 'removed' => 1], $this->lastAudit('people.access_changed')->detail);
        self::assertSame([[null, 'problem.people.admin_has_all', []]], self::problems(fn () => $people->setProjectAccess($this->admin, $this->admin->userKey, [$garden->key])));
    }

    /** @return iterable<string, array{\Closure(PeopleOperations, Actor, string): mixed}> */
    public static function methods(): iterable
    {
        yield 'list' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->list($a)];
        yield 'get' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->get($a, $k)];
        yield 'suggestPassword' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->suggestPassword($a)];
        yield 'create' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->create($a, 'example.cook', 'Example Cook', Role::Member, self::TEMPORARY, [])];
        yield 'update' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->update($a, $k, 'member2', 'Renamed', Role::Admin)];
        yield 'setTemporaryPassword' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->setTemporaryPassword($a, $k, self::TEMPORARY)];
        yield 'deactivate' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->deactivate($a, $k)];
        yield 'reactivate' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->reactivate($a, $k)];
        yield 'projectAccess' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->projectAccess($a, $k)];
        yield 'setProjectAccess' => [static fn (PeopleOperations $p, Actor $a, string $k) => $p->setProjectAccess($a, $k, [])];
    }

    #[DataProvider('methods')]
    public function testEveryMethodRefusesAMember(\Closure $call): void
    {
        $before = $this->fx->systemRows();
        $this->assertThrows(AccessDenied::class, fn () => $call($this->ops->people(), $this->member, $this->member2->userKey));
        self::assertSame($before, $this->fx->systemRows());
        $call($this->ops->people(), $this->admin, $this->member2->userKey);
    }

    /** @return iterable<string, array{string}> */
    public static function guardedChanges(): iterable
    {
        foreach (['create', 'update', 'setTemporaryPassword', 'deactivate', 'reactivate', 'setProjectAccess', 'setSharing', 'createProject'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * An Admin demoted or deactivated after their session resumed still
     * holds an Admin actor; the change re-reads them inside its write and
     * refuses.
     */
    #[DataProvider('guardedChanges')]
    public function testAStaleAdminActorIsRefusedInsideTheWrite(string $change): void
    {
        $project = $this->ops->projects()->create($this->admin, 'Example Garden', null);
        $other = $this->ops->people()->create($this->admin, 'example.owner', 'Example Owner', Role::Admin, self::TEMPORARY, []);
        $this->ops->people()->deactivate($this->admin, $this->member2->userKey);
        $calls = [
            'create' => fn () => $this->ops->people()->create($this->admin, 'example.cook', 'Example Cook', Role::Member, self::TEMPORARY, []),
            'update' => fn () => $this->ops->people()->update($this->admin, $this->member->userKey, 'member', 'Renamed', Role::Admin),
            'setTemporaryPassword' => fn () => $this->ops->people()->setTemporaryPassword($this->admin, $this->member->userKey, self::TEMPORARY),
            'deactivate' => fn () => $this->ops->people()->deactivate($this->admin, $this->member->userKey),
            'reactivate' => fn () => $this->ops->people()->reactivate($this->admin, $this->member2->userKey),
            'setProjectAccess' => fn () => $this->ops->people()->setProjectAccess($this->admin, $this->member->userKey, [$project->key]),
            'setSharing' => fn () => $this->ops->projects()->setSharing($this->admin, $project->key, [$this->member->userKey]),
            'createProject' => fn () => $this->ops->projects()->create($this->admin, 'Example Books', null),
        ];
        foreach (['demoted' => "UPDATE users SET role = 'member' WHERE id = ?", 'deactivated' => 'UPDATE users SET active = 0 WHERE id = ?'] as $how => $sql) {
            $db = $this->fx->system->db();
            $id = $this->admin->userId;
            $db->write(static fn () => $db->run($sql, [$id]));
            $before = $this->fx->systemRows();
            $this->assertThrows(AccessDenied::class, $calls[$change]);
            self::assertSame($before, $this->fx->systemRows(), $how);
            $db->write(static fn () => $db->run("UPDATE users SET role = 'admin', active = 1 WHERE id = ?", [$id]));
        }
        // Restored, the same call goes through.
        $calls[$change]();
        self::assertNotNull($other);
    }

    /**
     * @param list<ProjectAccess> $access
     * @return array<string, bool>
     */
    private function accessMap(array $access): array
    {
        $map = [];
        foreach ($access as $row) {
            $map[$row->project->name] = $row->granted;
        }
        ksort($map);

        return $map;
    }
}

