<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\DisplayNames;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Usernames;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\System\AuditAction;

/**
 * Managing people (M1 spec section 9.3). Every method needs
 * `people.manage`, checked with Authorizer first and, for every change,
 * again inside the system-database write against the acting person's
 * current row (role and active flag), so that an Admin demoted or
 * deactivated after their session resumed cannot act on its strength.
 *
 * The last-active-Admin rule is checked in the same write (BEGIN
 * IMMEDIATE) as the change it guards (P51), and ending the person's
 * sessions happens in that write too.
 */
final class PeopleOperations
{
    public const SUGGESTION_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    public const SUGGESTION_GROUPS = 3;
    public const SUGGESTION_GROUP_LENGTH = 4;

    public function __construct(private readonly Context $c)
    {
    }

    /** @return list<PersonSummary> by display name */
    public function list(Actor $actor): array
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $all = count($this->c->registry->all());
        $list = [];
        foreach ($this->c->users->all() as $person) {
            $count = $person->role === Role::Admin ? $all : count($this->c->registry->forMember($person->id));
            $list[] = new PersonSummary($person, $count);
        }

        return $list;
    }

    public function get(Actor $actor, string $personKey): UserRecord
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);

        return $this->person($personKey);
    }

    /** A temporary password to offer: three groups of four characters joined by '-' (M1 spec section 6.2, about 59 bits). */
    public function suggestPassword(Actor $actor): string
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $alphabet = self::SUGGESTION_ALPHABET;
        $size = strlen($alphabet);
        // Rejection sampling: bytes at or above the largest multiple of the alphabet size are drawn again, so every character is equally likely.
        $limit = intdiv(256, $size) * $size;
        $chars = '';
        $wanted = self::SUGGESTION_GROUPS * self::SUGGESTION_GROUP_LENGTH;
        while (strlen($chars) < $wanted) {
            foreach (str_split($this->c->random->bytes($wanted)) as $byte) {
                $value = ord($byte);
                if ($value < $limit && strlen($chars) < $wanted) {
                    $chars .= $alphabet[$value % $size];
                }
            }
        }

        return implode('-', str_split($chars, self::SUGGESTION_GROUP_LENGTH));
    }

    /**
     * Adds a person who must choose a new password at first sign-in. For a
     * Member, grants on the given projects (unknown or inactive keys are
     * ignored); for an Admin the keys are ignored.
     *
     * @param list<string> $projectKeys
     */
    public function create(
        Actor $actor,
        string $username,
        string $displayName,
        Role $role,
        #[\SensitiveParameter] string $temporaryPassword,
        array $projectKeys,
    ): UserRecord {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        self::fail([
            ...$this->usernameProblems($username, null),
            ...DisplayNames::problems($displayName),
            ...PasswordPolicy::problems($temporaryPassword, $username, 'temporary_password'),
        ]);
        $hash = $this->c->hasher->hash($temporaryPassword);

        $person = $this->c->db()->write(function () use ($actor, $username, $displayName, $role, $hash, $projectKeys): UserRecord {
            $this->c->requireCurrentAdmin($actor);
            self::fail($this->usernameProblems($username, null));
            $person = $this->c->users->create($username, $displayName, $role, $hash, true);
            if ($role === Role::Member) {
                $this->c->grants->setProjectsFor($person->id, $this->c->activeProjectIds($projectKeys), $actor->userId);
            }

            return $person;
        });
        $this->c->audit->record(AuditAction::PEOPLE_CREATED, $actor->userId, null, 'user', $person->key, ['role' => $role->value]);

        return $person;
    }

    /**
     * Changes a person's username, display name, and role. Demoting the
     * last active Admin is refused (`role`: `problem.people.last_admin`).
     * A change of role or username ends the person's sessions.
     */
    public function update(Actor $actor, string $personKey, string $username, string $displayName, Role $role): UserRecord
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $this->person($personKey);

        /** @var array{0: UserRecord, 1: UserRecord} $result */
        $result = $this->c->db()->write(function () use ($actor, $personKey, $username, $displayName, $role): array {
            $this->c->requireCurrentAdmin($actor);
            $before = $this->person($personKey);
            $problems = [...$this->usernameProblems($username, $before->id), ...DisplayNames::problems($displayName)];
            if ($before->role === Role::Admin && $before->active && $role !== Role::Admin
                && $this->c->users->countActiveAdmins() <= 1) {
                $problems[] = new Problem('role', 'problem.people.last_admin');
            }
            self::fail($problems);
            $this->c->seam('people.last_admin');
            $this->c->users->update($before->id, $username, $displayName, $role);
            $after = $this->person($personKey);
            if ($after->role !== $before->role || $after->username !== $before->username) {
                $this->c->sessions->endAllFor($before->id);
            }

            return [$before, $after];
        });
        [$before, $after] = $result;
        $this->c->audit->record(AuditAction::PEOPLE_UPDATED, $actor->userId, null, 'user', $after->key, [
            'role' => $after->role->value,
            'username_changed' => $after->username !== $before->username,
            'name_changed' => $after->displayName !== $before->displayName,
        ]);

        return $after;
    }

    /** Sets a temporary password the person must replace at next sign-in, and ends their sessions (the acting Admin's too). */
    public function setTemporaryPassword(Actor $actor, string $personKey, #[\SensitiveParameter] string $password): void
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $person = $this->person($personKey);
        self::fail(PasswordPolicy::problems($password, $person->username, 'temporary_password'));
        $hash = $this->c->hasher->hash($password);

        $this->c->db()->write(function () use ($actor, $personKey, $password, $hash): void {
            $this->c->requireCurrentAdmin($actor);
            $person = $this->person($personKey);
            // The username may have changed since the policy check above.
            self::fail(PasswordPolicy::problems($password, $person->username, 'temporary_password'));
            $this->c->users->setPassword($person->id, $hash, true);
            $this->c->sessions->endAllFor($person->id);
        });
        $this->c->audit->record(AuditAction::PEOPLE_PASSWORD_SET, $actor->userId, null, 'user', $person->key);
    }

    /** Stops a person from signing in and ends their sessions; grants are kept. Already inactive: nothing happens. */
    public function deactivate(Actor $actor, string $personKey): void
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $this->person($personKey);

        $changed = $this->c->db()->write(function () use ($actor, $personKey): bool {
            $this->c->requireCurrentAdmin($actor);
            $person = $this->person($personKey);
            if ($person->id === $actor->userId) {
                throw new ValidationFailed([new Problem(null, 'problem.people.self_deactivate')]);
            }
            if (!$person->active) {
                return false;
            }
            // Defence in depth: unreachable while the actor is an active Admin other than the target.
            if ($person->role === Role::Admin && $this->c->users->countActiveAdmins() <= 1) {
                throw new ValidationFailed([new Problem(null, 'problem.people.last_admin')]);
            }
            $this->c->seam('people.last_admin');
            $this->c->users->setActive($person->id, false);
            $this->c->sessions->endAllFor($person->id);

            return true;
        });
        if ($changed) {
            $this->c->audit->record(AuditAction::PEOPLE_DEACTIVATED, $actor->userId, null, 'user', $personKey);
        }
    }

    /** Lets a deactivated person sign in again. Already active: nothing happens. */
    public function reactivate(Actor $actor, string $personKey): void
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $this->person($personKey);

        $changed = $this->c->db()->write(function () use ($actor, $personKey): bool {
            $this->c->requireCurrentAdmin($actor);
            $person = $this->person($personKey);
            if ($person->active) {
                return false;
            }
            $this->c->users->setActive($person->id, true);

            return true;
        });
        if ($changed) {
            $this->c->audit->record(AuditAction::PEOPLE_REACTIVATED, $actor->userId, null, 'user', $personKey);
        }
    }

    /**
     * Every active project and whether the person can use it. An Admin can
     * use every project, so every row is granted for an Admin.
     *
     * @return list<ProjectAccess>
     */
    public function projectAccess(Actor $actor, string $personKey): array
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $person = $this->person($personKey);
        $granted = array_flip($this->c->grants->projectsFor($person->id));
        $rows = [];
        foreach ($this->c->registry->all() as $project) {
            $rows[] = new ProjectAccess($project, $person->role === Role::Admin || isset($granted[$project->id]));
        }

        return $rows;
    }

    /**
     * Sets a Member's grants to exactly the given active projects (unknown
     * keys ignored). For an Admin: null: `problem.people.admin_has_all`.
     *
     * @param list<string> $projectKeys
     */
    public function setProjectAccess(Actor $actor, string $personKey, array $projectKeys): void
    {
        $this->c->authorizer->require($actor, Permission::ManagePeople);
        $this->person($personKey);

        [$added, $removed] = $this->c->db()->write(function () use ($actor, $personKey, $projectKeys): array {
            $this->c->requireCurrentAdmin($actor);
            $person = $this->person($personKey);
            if ($person->role !== Role::Member) {
                throw new ValidationFailed([new Problem(null, 'problem.people.admin_has_all')]);
            }
            $wanted = $this->c->activeProjectIds($projectKeys);
            // Grants on projects that are not active (still being created) are not the caller's to change.
            $active = [];
            foreach ($this->c->registry->all() as $project) {
                $active[$project->id] = true;
            }
            foreach ($this->c->grants->projectsFor($person->id) as $projectId) {
                if (!isset($active[$projectId])) {
                    $wanted[] = $projectId;
                }
            }

            return $this->c->grants->setProjectsFor($person->id, $wanted, $actor->userId);
        });
        $this->c->audit->record(AuditAction::PEOPLE_ACCESS_CHANGED, $actor->userId, null, 'user', $personKey, [
            'added' => $added,
            'removed' => $removed,
        ]);
    }

    private function person(string $personKey): UserRecord
    {
        $person = $this->c->users->byKey($personKey);
        if ($person === null) {
            throw new NotFound('No such person.');
        }

        return $person;
    }

    /** @return list<Problem> */
    private function usernameProblems(string $username, ?int $exceptId): array
    {
        $problems = Usernames::problems($username);
        if ($problems === [] && $this->c->users->usernameTaken(Text::trim($username), $exceptId)) {
            $problems[] = new Problem('username', 'problem.username.taken');
        }

        return $problems;
    }

    /** @param list<Problem> $problems */
    private static function fail(array $problems): void
    {
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }
    }
}
