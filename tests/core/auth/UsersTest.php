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
use TiffinDB\Core\Auth\GrantLevel;
use TiffinDB\Core\Auth\Grants;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUTH-4: people; also the grants repository. */
#[CoversClass(Users::class)]
#[CoversClass(Grants::class)]
#[CoversClass(UserRecord::class)]
final class UsersTest extends TestCase
{
    private TempDir $temp;
    private FixedClock $clock;
    private SystemDatabase $system;
    private Users $users;
    private Grants $grants;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-04-01T09:00:00Z');
        $random = new SeededRandom('tiffindb-users-test-seed-1');
        $this->system = SystemDatabase::create(new DataDirectory($this->temp->path(), 'data'), $this->clock, $random);
        $this->users = new Users($this->system, new Keys($random), $this->clock);
        $this->grants = new Grants($this->system, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function create(string $username, string $name, Role $role = Role::Member, bool $mustChange = false): UserRecord
    {
        return $this->system->db()->write(
            fn (): UserRecord => $this->users->create($username, $name, $role, 'hash-of-' . $username, $mustChange),
        );
    }

    public function testCreateInsertsThePersonAndADefaultSettingsRow(): void
    {
        $person = $this->create(' Example.Admin ', ' Example Admin ', Role::Admin, true);
        $this->assertTrue(Keys::isValid($person->key));
        $this->assertSame('Example.Admin', $person->username);
        $this->assertSame('Example Admin', $person->displayName);
        $this->assertSame(Role::Admin, $person->role);
        $this->assertTrue($person->active);
        $this->assertTrue($person->mustChangePassword);
        $this->assertSame('2026-04-01T09:00:00Z', $person->createdAt);
        $this->assertNull($person->lastSignInAt);

        $row = $this->system->db()->one('SELECT * FROM user_settings WHERE user_id = ?', [$person->id]);
        $this->assertSame([
            'user_id' => $person->id, 'theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter',
            'text_size' => 'standard', 'line_spacing' => 'standard', 'reduce_motion' => 0,
        ], $row);
        $this->assertSame([
            'theme' => 'light', 'accent' => 'teal', 'typeface' => 'inter',
            'text_size' => 'standard', 'line_spacing' => 'standard', 'reduce_motion' => 0,
        ], $this->users->settings($person->id));
        $this->assertSame('example.admin', $this->system->db()->value('SELECT username_norm FROM users WHERE id = ?', [$person->id]));
        $this->assertSame('hash-of- Example.Admin ', $this->users->passwordHash($person->id));
    }

    public function testSettingsAllowlistStoredValues(): void
    {
        $person = $this->create('member', 'Example Member');
        $this->system->db()->run("UPDATE user_settings SET theme = 'dark', accent = 'javascript:x', reduce_motion = 1 WHERE user_id = ?", [$person->id]);
        $settings = $this->users->settings($person->id);
        $this->assertSame('dark', $settings['theme']);
        $this->assertSame('teal', $settings['accent']);
        $this->assertSame(1, $settings['reduce_motion']);
    }

    public function testUsernamesAreUniqueIgnoringCase(): void
    {
        $person = $this->create('Member', 'Example Member');
        $this->assertTrue($this->users->usernameTaken('member'));
        $this->assertTrue($this->users->usernameTaken('MEMBER'));
        $this->assertTrue($this->users->usernameTaken(' member '));
        $this->assertFalse($this->users->usernameTaken('member', $person->id));
        $this->assertFalse($this->users->usernameTaken('member2'));
        $this->expectException(\PDOException::class);
        $this->create('MEMBER', 'Another Member');
    }

    public function testLookUps(): void
    {
        $person = $this->create('Member', 'Example Member');
        $this->assertSame($person->id, $this->users->byUsername('mEmBeR')?->id);
        $this->assertSame($person->id, $this->users->byKey($person->key)?->id);
        $this->assertSame($person->key, $this->users->byId($person->id)?->key);
        $this->assertNull($this->users->byUsername('nobody'));
        $this->assertNull($this->users->byUsername("memb\xffer"));
        $this->assertNull($this->users->byKey('NOT A KEY'));
        $this->assertNull($this->users->byId(999));
    }

    public function testAllIsOrderedByDisplayNameThenKey(): void
    {
        $this->create('zed', 'zed Person');
        $this->create('alpha', 'Alpha Person');
        $this->create('beta', 'beta Person');
        $names = array_map(static fn (UserRecord $p): string => $p->displayName, $this->users->all());
        $this->assertSame(['Alpha Person', 'beta Person', 'zed Person'], $names);
    }

    public function testCountActiveAdmins(): void
    {
        $this->assertSame(0, $this->users->countActiveAdmins());
        $first = $this->create('admin', 'Example Admin', Role::Admin);
        $second = $this->create('admin2', 'Another Admin', Role::Admin);
        $member = $this->create('member', 'Example Member');
        $this->assertSame(2, $this->users->countActiveAdmins());
        $this->system->db()->write(fn () => $this->users->setActive($second->id, false));
        $this->assertSame(1, $this->users->countActiveAdmins());
        $this->system->db()->write(fn () => $this->users->update($member->id, 'member', 'Example Member', Role::Admin));
        $this->assertSame(2, $this->users->countActiveAdmins());
        $this->system->db()->write(fn () => $this->users->update($first->id, 'admin', 'Example Admin', Role::Member));
        $this->assertSame(1, $this->users->countActiveAdmins());
        $this->assertFalse($this->users->byId($second->id)?->active);
    }

    public function testLastActiveAdminCheckAndChangeRunInOneTransaction(): void
    {
        $a = $this->create('admin', 'Example Admin', Role::Admin);
        $b = $this->create('admin2', 'Another Admin', Role::Admin);
        // The pattern PeopleOperations uses (P51): the count and the demotion it permits are one write().
        $demote = fn (UserRecord $person): bool => $this->system->db()->write(function () use ($person): bool {
            if ($this->users->countActiveAdmins() <= 1) {
                return false;
            }
            $this->users->update($person->id, $person->username, $person->displayName, Role::Member);

            return true;
        });
        $this->assertTrue($demote($a));
        $this->assertFalse($demote($b), 'The last active Admin stays an Admin.');
        $this->assertSame(1, $this->users->countActiveAdmins());
        $this->assertSame(Role::Admin, $this->users->byId($b->id)?->role);

        // A failure after the change rolls the change back with the check.
        try {
            $this->system->db()->write(function () use ($b): void {
                $this->users->setActive($b->id, false);
                throw new \RuntimeException('later step failed');
            });
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $this->users->countActiveAdmins());
    }

    public function testUpdateSetPasswordReplaceHashAndMarkSignedIn(): void
    {
        $person = $this->create('member', 'Example Member');
        $this->clock->advance(60);
        $this->system->db()->write(fn () => $this->users->update($person->id, 'Renamed', 'Renamed Person', Role::Member));
        $this->assertSame('Renamed', $this->users->byId($person->id)?->username);
        $this->assertSame($person->id, $this->users->byUsername('renamed')?->id);
        $this->assertNull($this->users->byUsername('member'));

        $this->clock->advance(60);
        $this->system->db()->write(fn () => $this->users->setPassword($person->id, 'new-hash', true));
        $row = $this->system->db()->one('SELECT password_hash, must_change_password, password_changed_at FROM users WHERE id = ?', [$person->id]);
        $this->assertSame(['password_hash' => 'new-hash', 'must_change_password' => 1, 'password_changed_at' => '2026-04-01T09:02:00Z'], $row);

        $this->clock->advance(60);
        $this->system->db()->write(fn () => $this->users->replaceHash($person->id, 'rehashed'));
        $row = $this->system->db()->one('SELECT password_hash, must_change_password, password_changed_at FROM users WHERE id = ?', [$person->id]);
        $this->assertSame(['password_hash' => 'rehashed', 'must_change_password' => 1, 'password_changed_at' => '2026-04-01T09:02:00Z'], $row);

        $this->system->db()->write(fn () => $this->users->markSignedIn($person->id));
        $this->assertSame('2026-04-01T09:03:00Z', $this->users->byId($person->id)?->lastSignInAt);
    }

    public function testRepositoryRefusesInvalidNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create('no spaces allowed', 'Example Person');
    }

    public function testChangesNeedTheCallersTransaction(): void
    {
        $person = $this->create('member', 'Example Member');
        $calls = [
            fn () => $this->users->create('other', 'Other Person', Role::Member, 'x', false),
            fn () => $this->users->update($person->id, 'member', 'Example Member', Role::Admin),
            fn () => $this->users->setPassword($person->id, 'x', false),
            fn () => $this->users->replaceHash($person->id, 'x'),
            fn () => $this->users->setActive($person->id, false),
            fn () => $this->users->markSignedIn($person->id),
            fn () => $this->grants->setUsersFor(1, [$person->id], $person->id),
            fn () => $this->grants->setProjectsFor($person->id, [1], $person->id),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail('Call ' . $i . ' ran outside a transaction.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(Role::Member, $this->users->byId($person->id)?->role);
    }

    public function testAnUnknownStoredRoleIsRefused(): void
    {
        $person = $this->create('member', 'Example Member');
        $this->system->db()->run("UPDATE users SET role = 'owner' WHERE id = ?", [$person->id]);
        $this->expectException(\UnexpectedValueException::class);
        $this->users->byId($person->id);
    }

    public function testGrants(): void
    {
        $admin = $this->create('admin', 'Example Admin', Role::Admin);
        $member = $this->create('member', 'Example Member');
        $member2 = $this->create('member2', 'Another Member');
        $registry = new ProjectRegistry($this->system);
        [$p1, $p2] = $this->system->db()->write(fn (): array => [
            $registry->add('recipesaaaaa', 'Recipes', $admin->id, '2026-04-01T09:00:00Z'),
            $registry->add('booksaaaaaaa', 'Books', $admin->id, '2026-04-01T09:00:00Z'),
        ]);

        $this->assertNull($this->grants->level($p1->id, $member->id));
        $result = $this->system->db()->write(fn () => $this->grants->setUsersFor($p1->id, [$member->id, $member2->id, $member->id], $admin->id));
        $this->assertSame([2, 0], $result);
        $this->assertSame(GrantLevel::Edit, $this->grants->level($p1->id, $member->id));
        $this->assertSame([$member->id, $member2->id], $this->grants->usersFor($p1->id));
        $this->assertSame(2, $this->grants->countMembersFor($p1->id));

        $result = $this->system->db()->write(fn () => $this->grants->setUsersFor($p1->id, [$member2->id, $admin->id], $admin->id));
        $this->assertSame([1, 1], $result);
        $this->assertSame([$admin->id, $member2->id], $this->grants->usersFor($p1->id));
        $this->assertSame(1, $this->grants->countMembersFor($p1->id), 'Only Members count.');
        $this->system->db()->write(fn () => $this->users->setActive($member2->id, false));
        $this->assertSame(0, $this->grants->countMembersFor($p1->id), 'Only active Members count.');

        $result = $this->system->db()->write(fn () => $this->grants->setProjectsFor($member->id, [$p2->id, $p1->id], $admin->id));
        $this->assertSame([2, 0], $result);
        $this->assertSame([$p1->id, $p2->id], $this->grants->projectsFor($member->id));
        $result = $this->system->db()->write(fn () => $this->grants->setProjectsFor($member->id, [], $admin->id));
        $this->assertSame([0, 2], $result);
        $this->assertSame([], $this->grants->projectsFor($member->id));

        $row = $this->system->db()->one('SELECT level, granted_at, granted_by FROM project_grants WHERE project_id = ? AND user_id = ?', [$p1->id, $member2->id]);
        $this->assertSame(['level' => 'edit', 'granted_at' => '2026-04-01T09:00:00Z', 'granted_by' => $admin->id], $row);

        $this->system->db()->run("UPDATE project_grants SET level = 'owner' WHERE project_id = ? AND user_id = ?", [$p1->id, $member2->id]);
        $this->assertNull($this->grants->level($p1->id, $member2->id), 'An unknown stored level is no grant.');

        $this->expectException(\InvalidArgumentException::class);
        $this->system->db()->write(fn () => $this->grants->setUsersFor($p1->id, ['1'], $admin->id));
    }
}
