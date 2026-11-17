<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The project registry in the system database (M1 spec section 5.4).
 *
 * A repository (P51): it runs plain statements and never opens write().
 * Methods that change rows must be called inside the caller's write()
 * transaction and throw \LogicException otherwise. No permission checks.
 */
final class ProjectRegistry
{
    private const COLUMNS = 'id, key, name, state, created_at, created_by';

    public function __construct(private readonly SystemDatabase $system)
    {
    }

    /** Adds a project in state 'creating'. $now is a Timestamp string. */
    public function add(string $key, string $name, int $createdBy, string $now): ProjectRecord
    {
        $this->system->requireTransaction();
        if (!Keys::isValid($key)) {
            throw new \InvalidArgumentException('Not a valid project key.');
        }
        Timestamp::parse($now);
        $this->system->db()->run(
            'INSERT INTO projects (key, name, state, created_at, created_by) VALUES (?, ?, ?, ?, ?)',
            [$key, $name, ProjectRecord::STATE_CREATING, $now, $createdBy],
        );
        $record = $this->byKey($key);
        if ($record === null) {
            throw new \LogicException('The project just added cannot be read back.');
        }

        return $record;
    }

    public function activate(int $id): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('UPDATE projects SET state = ? WHERE id = ?', [ProjectRecord::STATE_ACTIVE, $id]);
    }

    /** Only used to undo a failed create. */
    public function remove(int $id): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('DELETE FROM projects WHERE id = ?', [$id]);
    }

    /** The project with this key in any state, or null. */
    public function byKey(string $key): ?ProjectRecord
    {
        if (!Keys::isValid($key)) {
            return null;
        }
        $row = $this->system->db()->one('SELECT ' . self::COLUMNS . ' FROM projects WHERE key = ?', [$key]);

        return $row === null ? null : self::record($row);
    }

    /** Whether a project other than $exceptId (in any state) has this name, ignoring ASCII case. */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $found = $this->system->db()->value(
            'SELECT 1 FROM projects WHERE name = ? COLLATE NOCASE AND id IS NOT ?',
            [$name, $exceptId],
        );

        return $found !== null;
    }

    public function rename(int $id, string $name): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('UPDATE projects SET name = ? WHERE id = ?', [$name, $id]);
    }

    /**
     * Active projects, ordered by name COLLATE NOCASE then key.
     *
     * @return list<ProjectRecord>
     */
    public function all(): array
    {
        $rows = $this->system->db()->all(
            'SELECT ' . self::COLUMNS . ' FROM projects WHERE state = ? ORDER BY name COLLATE NOCASE, key',
            [ProjectRecord::STATE_ACTIVE],
        );

        return array_map(self::record(...), $rows);
    }

    /**
     * Active projects the person holds a grant for, same order.
     *
     * @return list<ProjectRecord>
     */
    public function forMember(int $userId): array
    {
        $rows = $this->system->db()->all(
            'SELECT p.id, p.key, p.name, p.state, p.created_at, p.created_by
               FROM projects p JOIN project_grants g ON g.project_id = p.id
              WHERE p.state = ? AND g.user_id = ?
              ORDER BY p.name COLLATE NOCASE, p.key',
            [ProjectRecord::STATE_ACTIVE, $userId],
        );

        return array_map(self::record(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): ProjectRecord
    {
        return new ProjectRecord(
            (int) $row['id'],
            (string) $row['key'],
            (string) $row['name'],
            (string) $row['state'],
            (string) $row['created_at'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
        );
    }
}
