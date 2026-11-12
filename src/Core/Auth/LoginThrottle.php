<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\SystemDatabase;

/**
 * Sign-in throttling (ADR 0011 decision 6, M1 spec section 6.6, P31, P60).
 *
 * Buckets are keyed by an HMAC of the normalised username or of the client
 * address with the installation's key, so no raw username or address is
 * stored. The client address is REMOTE_ADDR as the server gives it;
 * forwarding headers are ignored (by the web layer, which passes it in).
 * There is no permanent lockout: waits start at 30 seconds and double up
 * to 15 minutes.
 *
 * Reset rule (P65, amending section 6.6): a bucket starts again from zero
 * only when its last failure is more than WINDOW_SECONDS old and it is not
 * blocked now. Someone who waits out each block therefore keeps doubling
 * (30, 60, 120, 240, 480, 900, 900, ...), as ADR 0011 intends.
 *
 * A repository (P51): failure() and clear() throw \LogicException outside
 * the caller's write() transaction, so that a wait() check and the
 * failure it leads to are one transaction and parallel guesses cannot all
 * pass the check before any is counted.
 */
final class LoginThrottle
{
    public const WINDOW_SECONDS = 900;
    public const USER_LIMIT = 5;
    public const ADDRESS_LIMIT = 20;
    public const SETUP_LIMIT = 5;
    public const FIRST_WAIT = 30;
    public const MAX_WAIT = 900;
    public const SETUP_BUCKET = 's:setup';
    /** Unblocked rows whose last failure is older than this are deleted. */
    public const STALE_SECONDS = 86400;

    public function __construct(private readonly SystemDatabase $system, private readonly Clock $clock)
    {
    }

    public static function userBucket(SystemDatabase $s, string $usernameNorm): string
    {
        return 'u:' . $s->hmac('throttle-user', $usernameNorm);
    }

    public static function addressBucket(SystemDatabase $s, string $address): string
    {
        return 'a:' . $s->hmac('throttle-address', $address);
    }

    /**
     * Seconds to wait (0 = go ahead): the longest remaining block among the buckets.
     *
     * @param list<string> $buckets
     */
    public function wait(array $buckets): int
    {
        if ($buckets === []) {
            return 0;
        }
        foreach ($buckets as $bucket) {
            self::checkBucket($bucket);
        }
        $now = $this->clock->now();
        $latest = $this->system->db()->value(
            'SELECT MAX(blocked_until) FROM login_throttle WHERE bucket IN (' . Database::placeholders(count($buckets)) . ')
                AND blocked_until IS NOT NULL AND blocked_until > ?',
            [...array_values($buckets), Timestamp::format($now)],
        );
        if (!is_string($latest)) {
            return 0;
        }

        return max(0, Timestamp::parse($latest)->getTimestamp() - $now->getTimestamp());
    }

    /** Counts one failure; at $limit failures or more since the last reset, starts or lengthens a block. */
    public function failure(string $bucket, int $limit): void
    {
        $this->system->requireTransaction();
        self::checkBucket($bucket);
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be 1 or more.');
        }
        $db = $this->system->db();
        $now = $this->clock->now();
        $nowText = Timestamp::format($now);
        $stale = Timestamp::format($now->modify('-' . self::STALE_SECONDS . ' seconds'));
        $db->run(
            'DELETE FROM login_throttle WHERE last_failure_at < ? AND (blocked_until IS NULL OR blocked_until <= ?)',
            [$stale, $nowText],
        );

        $row = $db->one('SELECT failures, window_started_at, last_failure_at, blocked_until FROM login_throttle WHERE bucket = ?', [$bucket]);
        $failures = 0;
        $windowStart = $nowText;
        if ($row !== null) {
            $blockedNow = $row['blocked_until'] !== null && strcmp((string) $row['blocked_until'], $nowText) > 0;
            $quietFor = $now->getTimestamp() - Timestamp::parse((string) $row['last_failure_at'])->getTimestamp();
            if ($blockedNow || $quietFor <= self::WINDOW_SECONDS) {
                $failures = (int) $row['failures'];
                $windowStart = (string) $row['window_started_at'];
            }
        }
        $failures++;
        $blockedUntil = null;
        if ($failures >= $limit) {
            $blockedUntil = Timestamp::format($now->modify('+' . self::waitFor($failures - $limit) . ' seconds'));
        }
        $db->run(
            'INSERT INTO login_throttle (bucket, failures, window_started_at, last_failure_at, blocked_until) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (bucket) DO UPDATE SET failures = excluded.failures, window_started_at = excluded.window_started_at,
                 last_failure_at = excluded.last_failure_at, blocked_until = excluded.blocked_until',
            [$bucket, $failures, $windowStart, $nowText, $blockedUntil],
        );
    }

    public function clear(string $bucket): void
    {
        $this->system->requireTransaction();
        self::checkBucket($bucket);
        $this->system->db()->run('DELETE FROM login_throttle WHERE bucket = ?', [$bucket]);
    }

    /** min(30 × 2^$excess, 900) seconds, without overflow. */
    public static function waitFor(int $excess): int
    {
        if ($excess < 0) {
            return 0;
        }
        $wait = self::FIRST_WAIT;
        for ($i = 0; $i < $excess && $wait < self::MAX_WAIT; $i++) {
            $wait *= 2;
        }

        return min($wait, self::MAX_WAIT);
    }

    private static function checkBucket(mixed $bucket): void
    {
        if (!is_string($bucket) || preg_match('/^(?:[ua]:[0-9a-f]{64}|s:[a-z]+)\z/', $bucket) !== 1) {
            throw new \InvalidArgumentException('Not a throttle bucket.');
        }
    }
}
