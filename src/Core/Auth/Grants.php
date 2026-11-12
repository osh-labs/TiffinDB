<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\SystemDatabase;

/**
 * Project grants (ADR 0002, M1 spec section 6.4). A repository (P51): no
 * write() of its own, no permission checks; methods that change rows
 * throw \LogicException outside the caller's write() transaction.
 *
 * A stored level that is not a GrantLevel reads as no grant.
 */
final class Grants
{
    public function __construct(private readonly SystemDatabase $system, private readonly Clock $clock)
    {
    }

    public function level(int $projectId, int $userId): ?GrantLevel
    {
        $level = $this->system->db()->value(
            'SELECT level FROM project_grants WHERE project_id = ? AND user_id = ?',
            [$projectId, $userId],
        );

        return is_string($level) ? GrantLevel::tryFrom($level) : null;
    }

    /** @return list<int> user ids, ascending */
    public function usersFor(int $projectId): array
    {
        return $this->ids('SELECT user_id FROM project_grants WHERE project_id = ? ORDER BY user_id', $projectId);
    }

    /** @return list<int> project ids, ascending */
    public function projectsFor(int $userId): array
    {
        return $this->ids('SELECT project_id FROM project_grants WHERE user_id = ? ORDER BY project_id', $userId);
    }

    /**
     * Replaces the set of people holding a grant on the project.
     *
     * @param list<int> $userIds
     * @return array{0: int, 1: int} [added, removed]
     */
    public function setUsersFor(int $projectId, array $userIds, int $grantedBy): array
    {
        $this->system->requireTransaction();
        $wanted = self::uniqueIds($userIds);
        $current = $this->usersFor($projectId);
        $add = array_values(array_diff($wanted, $current));
        $remove = array_values(array_diff($current, $wanted));
        foreach ($remove as $userId) {
            $this->delete($projectId, $userId);
        }
        foreach ($add as $userId) {
            $this->insert($projectId, $userId, $grantedBy);
        }

        return [count($add), count($remove)];
    }

    /**
     * Replaces the set of projects the person holds a grant on.
     *
     * @param list<int> $projectIds
     * @return array{0: int, 1: int} [added, removed]
     */
    public function setProjectsFor(int $userId, array $projectIds, int $grantedBy): array
    {
        $this->system->requireTransaction();
        $wanted = self::uniqueIds($projectIds);
        $current = $this->projectsFor($userId);
        $add = array_values(array_diff($wanted, $current));
        $remove = array_values(array_diff($current, $wanted));
        foreach ($remove as $projectId) {
            $this->delete($projectId, $userId);
        }
        foreach ($add as $projectId) {
            $this->insert($projectId, $userId, $grantedBy);
        }

        return [count($add), count($remove)];
    }

    /** Grants on the project held by active Members. */
    public function countMembersFor(int $projectId): int
    {
        return (int) $this->system->db()->value(
            'SELECT COUNT(*) FROM project_grants g JOIN users u ON u.id = g.user_id
              WHERE g.project_id = ? AND u.role = ? AND u.active = 1',
            [$projectId, Role::Member->value],
        );
    }

    /** @return list<int> */
    private function ids(string $sql, int $id): array
    {
        return array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $this->system->db()->all($sql, [$id]),
        );
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function uniqueIds(array $ids): array
    {
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Ids must be positive integers.');
            }
        }

        return array_values(array_unique($ids));
    }

    private function insert(int $projectId, int $userId, int $grantedBy): void
    {
        $this->system->db()->run(
            'INSERT INTO project_grants (project_id, user_id, level, granted_at, granted_by) VALUES (?, ?, ?, ?, ?)',
            [$projectId, $userId, GrantLevel::Edit->value, Timestamp::format($this->clock->now()), $grantedBy],
        );
    }

    private function delete(int $projectId, int $userId): void
    {
        $this->system->db()->run('DELETE FROM project_grants WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
    }
}
