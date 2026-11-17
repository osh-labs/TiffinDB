<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\SystemDatabase;

/**
 * People in the system database (M1 spec section 6.4).
 *
 * A repository (P51): plain statements, no write() of its own, no
 * permission checks. Methods that change rows throw \LogicException unless
 * the caller's write() transaction is open, so that a check (for example
 * the last-active-Admin rule, or a username's uniqueness) and the change
 * it guards are one transaction.
 *
 * As a last line of defence, create() and update() refuse with
 * \InvalidArgumentException a username or display name that breaks the
 * rules; callers validate first and report problems with ValidationFailed.
 * Both are stored trimmed.
 */
final class Users
{
    /** user_settings columns => allowed values, default first (section 5.5). */
    public const SETTINGS = [
        'theme' => ['light', 'dark', 'hc-light', 'hc-dark'],
        'accent' => ['teal', 'indigo', 'plum', 'graphite'],
        'typeface' => ['inter', 'atkinson', 'lexend', 'source-serif', 'device-sans', 'device-serif'],
        'text_size' => ['standard', 'small', 'large', 'larger', 'largest'],
        'line_spacing' => ['standard', 'roomy', 'very-roomy'],
        'reduce_motion' => [0, 1],
    ];

    private const COLUMNS = 'id, key, username, display_name, role, active, must_change_password, created_at, last_sign_in_at';

    public function __construct(
        private readonly SystemDatabase $system,
        private readonly Keys $keys,
        private readonly Clock $clock,
    ) {
    }

    /** Creates a person and their default user_settings row. */
    public function create(string $username, string $displayName, Role $role, string $passwordHash, bool $mustChange): UserRecord
    {
        $this->system->requireTransaction();
        [$username, $displayName] = self::checked($username, $displayName);
        $db = $this->system->db();
        $key = $this->keys->generateUnique(
            static fn (string $key): bool => $db->value('SELECT 1 FROM users WHERE key = ?', [$key]) !== null,
        );
        $now = $this->now();
        $db->run(
            'INSERT INTO users (key, username, username_norm, display_name, role, password_hash, must_change_password,
                                active, created_at, updated_at, password_changed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$key, $username, Usernames::normalise($username), $displayName, $role->value, $passwordHash,
             $mustChange ? 1 : 0, $now, $now, $now],
        );
        $id = $db->lastInsertId();
        $db->run('INSERT INTO user_settings (user_id) VALUES (?)', [$id]);
        $record = $this->byId($id);
        if ($record === null) {
            throw new \LogicException('The person just created cannot be read back.');
        }

        return $record;
    }

    public function byId(int $id): ?UserRecord
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    public function byKey(string $key): ?UserRecord
    {
        if (!Keys::isValid($key)) {
            return null;
        }

        return $this->one('SELECT ' . self::COLUMNS . ' FROM users WHERE key = ?', [$key]);
    }

    /** Looks the username up after normalising it (trim, ASCII lower case). */
    public function byUsername(string $username): ?UserRecord
    {
        if (!Text::isUtf8($username)) {
            return null;
        }

        return $this->one('SELECT ' . self::COLUMNS . ' FROM users WHERE username_norm = ?', [Usernames::normalise($username)]);
    }

    public function passwordHash(int $id): string
    {
        $hash = $this->system->db()->value('SELECT password_hash FROM users WHERE id = ?', [$id]);
        if (!is_string($hash)) {
            throw new \InvalidArgumentException('No such person.');
        }

        return $hash;
    }

    public function usernameTaken(string $username, ?int $exceptId = null): bool
    {
        return $this->system->db()->value(
            'SELECT 1 FROM users WHERE username_norm = ? AND id IS NOT ?',
            [Usernames::normalise($username), $exceptId],
        ) !== null;
    }

    /**
     * Ordered by display name COLLATE NOCASE, then key.
     *
     * @return list<UserRecord>
     */
    public function all(): array
    {
        $rows = $this->system->db()->all('SELECT ' . self::COLUMNS . ' FROM users ORDER BY display_name COLLATE NOCASE, key');

        return array_map(self::record(...), $rows);
    }

    public function update(int $id, string $username, string $displayName, Role $role): void
    {
        $this->system->requireTransaction();
        [$username, $displayName] = self::checked($username, $displayName);
        $this->system->db()->run(
            'UPDATE users SET username = ?, username_norm = ?, display_name = ?, role = ?, updated_at = ? WHERE id = ?',
            [$username, Usernames::normalise($username), $displayName, $role->value, $this->now(), $id],
        );
    }

    /** A new password (set by the person, an Admin, or the reset script); sets password_changed_at. */
    public function setPassword(int $id, string $hash, bool $mustChange): void
    {
        $this->system->requireTransaction();
        $now = $this->now();
        $this->system->db()->run(
            'UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = ?, updated_at = ? WHERE id = ?',
            [$hash, $mustChange ? 1 : 0, $now, $now, $id],
        );
    }

    /**
     * Replaces the stored hash of the same password with one made with the
     * current algorithm or cost (rehash on sign-in, ADR 0011 decision 4).
     * Unlike setPassword() it changes neither password_changed_at nor
     * must_change_password.
     */
    public function replaceHash(int $id, string $hash): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run(
            'UPDATE users SET active = ?, updated_at = ? WHERE id = ?',
            [$active ? 1 : 0, $this->now(), $id],
        );
    }

    public function markSignedIn(int $id): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('UPDATE users SET last_sign_in_at = ? WHERE id = ?', [$this->now(), $id]);
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->system->db()->value(
            'SELECT COUNT(*) FROM users WHERE role = ? AND active = 1',
            [Role::Admin->value],
        );
    }

    /**
     * The person's user_settings as column => value. A stored value not in
     * SETTINGS, or a missing row, gives that column's default, so the web
     * layer only ever sees allowed values.
     *
     * @return array<string, string|int>
     */
    public function settings(int $id): array
    {
        $row = $this->system->db()->one(
            'SELECT theme, accent, typeface, text_size, line_spacing, reduce_motion FROM user_settings WHERE user_id = ?',
            [$id],
        ) ?? [];
        $settings = [];
        foreach (self::SETTINGS as $column => $allowed) {
            $value = $row[$column] ?? null;
            $settings[$column] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        return $settings;
    }

    /**
     * @return array{0: string, 1: string} trimmed username and display name
     */
    private static function checked(string $username, string $displayName): array
    {
        if (Usernames::problems($username) !== [] || DisplayNames::problems($displayName) !== []) {
            throw new \InvalidArgumentException('The username or display name breaks the rules; validate before storing.');
        }

        return [Text::trim($username), Text::trim($displayName)];
    }

    private function now(): string
    {
        return Timestamp::format($this->clock->now());
    }

    /** @param list<mixed> $params */
    private function one(string $sql, array $params): ?UserRecord
    {
        $row = $this->system->db()->one($sql, $params);

        return $row === null ? null : self::record($row);
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): UserRecord
    {
        $role = Role::tryFrom((string) $row['role']);
        if ($role === null) {
            throw new \UnexpectedValueException('A stored role is not recognised.');
        }

        return new UserRecord(
            (int) $row['id'],
            (string) $row['key'],
            (string) $row['username'],
            (string) $row['display_name'],
            $role,
            (int) $row['active'] === 1,
            (int) $row['must_change_password'] === 1,
            (string) $row['created_at'],
            $row['last_sign_in_at'] === null ? null : (string) $row['last_sign_in_at'],
        );
    }
}
