<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\SystemDatabase;

/**
 * Server-side sessions (ADR 0011 decision 5, M1 spec section 6.5, P30).
 *
 * The cookie token is 32 random bytes, base64url without padding (43
 * characters); only its lower-case hex SHA-256 is stored. The token is
 * never stored, logged, or put in the audit log or an exception message.
 * A session ends 7 days after its last use or 30 days after it started,
 * whichever is first; "now" equal to an expiry time counts as expired.
 *
 * A repository (P51): no write() of its own. Every method that may change
 * rows (start, resolve, end, endAllFor, setFlash, takeFlash) throws
 * \LogicException outside the caller's write() transaction.
 */
final class Sessions
{
    public const IDLE_SECONDS = 604800;
    public const ABSOLUTE_SECONDS = 2592000;
    public const TOUCH_SECONDS = 300;
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';
    private const FLASH_KEY = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*\z/';

    public function __construct(
        private readonly SystemDatabase $system,
        private readonly Clock $clock,
        private readonly Random $random,
    ) {
    }

    /** A new session for the person; expired sessions of anyone are deleted first. */
    public function start(int $userId): IssuedSession
    {
        $this->system->requireTransaction();
        $db = $this->system->db();
        $now = $this->clock->now();
        $nowText = Timestamp::format($now);
        $db->run('DELETE FROM sessions WHERE idle_expires_at <= ? OR absolute_expires_at <= ?', [$nowText, $nowText]);

        $token = rtrim(strtr(base64_encode($this->random->bytes(32)), '+/', '-_'), '=');
        $csrf = bin2hex($this->random->bytes(32));
        $absolute = Timestamp::format($now->modify('+' . self::ABSOLUTE_SECONDS . ' seconds'));
        $idle = Timestamp::format($now->modify('+' . self::IDLE_SECONDS . ' seconds'));
        $record = new SessionRecord(self::hashToken($token), $userId, $csrf, $nowText, $absolute);
        $db->run(
            'INSERT INTO sessions (token_hash, user_id, csrf_token, flash, created_at, last_seen_at, idle_expires_at, absolute_expires_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)',
            [$record->tokenHash, $userId, $csrf, $nowText, $nowText, $idle, $absolute],
        );

        return new IssuedSession($token, $record);
    }

    /**
     * The live session for a cookie token, or null for a malformed or
     * unknown token, an expired session (deleted), or a session whose
     * person is inactive (deleted). Moves the idle expiry forward when the
     * session was last seen more than TOUCH_SECONDS ago, never beyond the
     * absolute expiry.
     */
    public function resolve(#[\SensitiveParameter] string $token): ?SessionRecord
    {
        $this->system->requireTransaction();
        if (!self::wellFormed($token)) {
            return null;
        }
        $db = $this->system->db();
        $hash = self::hashToken($token);
        $row = $db->one(
            'SELECT s.token_hash, s.user_id, s.csrf_token, s.created_at, s.last_seen_at, s.idle_expires_at,
                    s.absolute_expires_at, u.active
               FROM sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ?',
            [$hash],
        );
        if ($row === null) {
            return null;
        }
        $now = $this->clock->now();
        $nowText = Timestamp::format($now);
        $expired = strcmp($nowText, (string) $row['idle_expires_at']) >= 0
            || strcmp($nowText, (string) $row['absolute_expires_at']) >= 0;
        if ($expired || (int) $row['active'] !== 1) {
            $db->run('DELETE FROM sessions WHERE token_hash = ?', [$hash]);

            return null;
        }
        $lastSeen = Timestamp::parse((string) $row['last_seen_at']);
        if ($now->getTimestamp() - $lastSeen->getTimestamp() > self::TOUCH_SECONDS) {
            $idle = Timestamp::format($now->modify('+' . self::IDLE_SECONDS . ' seconds'));
            $absolute = (string) $row['absolute_expires_at'];
            $db->run(
                'UPDATE sessions SET last_seen_at = ?, idle_expires_at = ? WHERE token_hash = ?',
                [$nowText, strcmp($idle, $absolute) < 0 ? $idle : $absolute, $hash],
            );
        }

        return new SessionRecord(
            $hash,
            (int) $row['user_id'],
            (string) $row['csrf_token'],
            (string) $row['created_at'],
            (string) $row['absolute_expires_at'],
        );
    }

    /** Ends the session for this token, if there is one. */
    public function end(#[\SensitiveParameter] string $token): void
    {
        $this->system->requireTransaction();
        if (!self::wellFormed($token)) {
            return;
        }
        $this->system->db()->run('DELETE FROM sessions WHERE token_hash = ?', [self::hashToken($token)]);
    }

    /** Ends every session of the person (password change or reset, role change, deactivation). */
    public function endAllFor(int $userId): void
    {
        $this->system->requireTransaction();
        $this->system->db()->run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    /**
     * One notice for the next page, replacing any earlier one.
     *
     * @param array<string, string|int> $params
     */
    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void
    {
        $this->system->requireTransaction();
        if (preg_match(self::FLASH_KEY, $key) !== 1) {
            throw new \InvalidArgumentException('A flash key must be a message key.');
        }
        foreach ($params as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_int($value))) {
                throw new \InvalidArgumentException('Flash parameters must map names to strings or integers.');
            }
        }
        if (!self::wellFormed($token)) {
            return;
        }
        $flash = json_encode(['key' => $key, 'params' => (object) $params], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->system->db()->run('UPDATE sessions SET flash = ? WHERE token_hash = ?', [$flash, self::hashToken($token)]);
    }

    /**
     * The notice, cleared on read.
     *
     * @return array{key: string, params: array<string, string|int>}|null
     */
    public function takeFlash(#[\SensitiveParameter] string $token): ?array
    {
        $this->system->requireTransaction();
        if (!self::wellFormed($token)) {
            return null;
        }
        $db = $this->system->db();
        $hash = self::hashToken($token);
        $flash = $db->value('SELECT flash FROM sessions WHERE token_hash = ?', [$hash]);
        if (!is_string($flash)) {
            return null;
        }
        $db->run('UPDATE sessions SET flash = NULL WHERE token_hash = ?', [$hash]);
        $decoded = json_decode($flash, true);
        if (!is_array($decoded) || !is_string($decoded['key'] ?? null) || !is_array($decoded['params'] ?? null)) {
            return null;
        }

        return ['key' => $decoded['key'], 'params' => $decoded['params']];
    }

    /** Whether the token has the shape of a session token (43 base64url characters). */
    public static function wellFormed(#[\SensitiveParameter] string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** Lower-case hex SHA-256 of the token, as stored in sessions.token_hash. */
    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
