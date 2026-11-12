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
 * A repository (P51): wait(), failure(), takeBack(), and clear() throw
 * \LogicException outside the caller's write() transaction, so that a
 * wait() check and the failure it leads to are one transaction and
 * parallel guesses cannot all pass the check before any is counted.
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

    /** The bucket of the client address, after canonicalAddress(). */
    public static function addressBucket(SystemDatabase $s, string $address): string
    {
        return 'a:' . $s->hmac('throttle-address', self::canonicalAddress($address));
    }

    /**
     * The form of a client address that is counted (P70): an IPv4 address
     * as inet_ntop writes it; an IPv6 address as its /64 prefix
     * (`2001:db8::/64`), since one household or device usually holds a whole
     * /64; an IPv4-mapped IPv6 address as its IPv4 address. Anything that
     * does not parse as an IP address is used as given.
     */
    public static function canonicalAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return $address;
        }
        $packed = inet_pton($address);
        if ($packed === false) {
            return $address;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            $text = inet_ntop($packed);

            return $text === false ? $address : $text;
        }
        $text = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

        return $text === false ? $address : $text . '/64';
    }

    /**
     * Seconds to wait (0 = go ahead): the longest remaining block among the
     * buckets. Throws \LogicException outside the caller's write()
     * transaction, so that the check and the failure counted on its strength
     * cannot be separated.
     *
     * @param list<string> $buckets
     */
    public function wait(array $buckets): int
    {
        $this->system->requireTransaction();
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
    public function failure(string $bucket, int $limit): ThrottleState
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

        return new ThrottleState($failures, $windowStart, $nowText, $blockedUntil);
    }

    /** The bucket's row as it is now, or null when there is none. */
    public function state(string $bucket): ?ThrottleState
    {
        self::checkBucket($bucket);
        $row = $this->system->db()->one(
            'SELECT failures, window_started_at, last_failure_at, blocked_until FROM login_throttle WHERE bucket = ?',
            [$bucket],
        );

        return $row === null ? null : new ThrottleState(
            (int) $row['failures'],
            (string) $row['window_started_at'],
            (string) $row['last_failure_at'],
            $row['blocked_until'] === null ? null : (string) $row['blocked_until'],
        );
    }

    /**
     * Withdraws one failure counted in advance (a sign-in that then
     * succeeded, P68). $before is the row as it was just before that
     * failure() call (null: there was none) and $counted what that call
     * wrote.
     *
     * When the row still holds exactly $counted, no other failure has been
     * counted since, and the row is put back as it was before (deleted when
     * there was none): its block, its last failure time, and its count, so
     * a success is net-zero. Otherwise later failures have changed it: the
     * count is lowered by one, the block those failures set is kept, and it
     * is lifted only when the count falls below $limit.
     */
    public function takeBack(string $bucket, int $limit, ?ThrottleState $before, ThrottleState $counted): void
    {
        $this->system->requireTransaction();
        self::checkBucket($bucket);
        if ($limit < 1) {
            throw new \InvalidArgumentException('The limit must be 1 or more.');
        }
        $db = $this->system->db();
        $now = $this->state($bucket);
        if ($now !== null && $now->equals($counted)) {
            if ($before === null) {
                $db->run('DELETE FROM login_throttle WHERE bucket = ?', [$bucket]);
            } else {
                $db->run(
                    'UPDATE login_throttle SET failures = ?, window_started_at = ?, last_failure_at = ?, blocked_until = ? WHERE bucket = ?',
                    [$before->failures, $before->windowStartedAt, $before->lastFailureAt, $before->blockedUntil, $bucket],
                );
            }

            return;
        }
        $db->run('UPDATE login_throttle SET failures = failures - 1 WHERE bucket = ? AND failures > 0', [$bucket]);
        $db->run('UPDATE login_throttle SET blocked_until = NULL WHERE bucket = ? AND failures < ?', [$bucket, $limit]);
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
