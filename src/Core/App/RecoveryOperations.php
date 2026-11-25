<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Usernames;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Install\RecoveryCode;
use TiffinDB\Core\Install\SetupCode;
use TiffinDB\Core\System\AuditAction;

/**
 * Admin recovery by a code file (M7 spec section 9, ADR 0022 decision 5,
 * P224 to P227, P237). There is no Actor: the person is signed out.
 *
 * Recovery never signs anyone in, never tells whether a username exists
 * or is an Admin, never shows or logs the code, writes nothing outside the
 * data folder, and never works for a Member or an inactive account.
 *
 * The attempt and start limits are global buckets (`s:recovery`,
 * `s:recoverystart`), so every visitor counts together (P237); attempts
 * also count on the address bucket that sign-in uses.
 */
final class RecoveryOperations
{
    public const START_BUCKET = 's:recoverystart';
    public const ATTEMPT_BUCKET = 's:recovery';
    public const LIMIT = 5;

    private const NOT_REMOVED = 'TiffinDB: the recovery code file could not be removed; delete it by hand.';

    public function __construct(private readonly Context $c)
    {
    }

    /**
     * Writes a new code file unless a valid one exists, or does nothing
     * while starts are throttled. Says nothing either way. $clientAddress
     * is not used: starts are counted in one bucket for everyone (P76).
     */
    public function start(bool $enabled, string $clientAddress): void
    {
        if (!$enabled) {
            throw new NotFound('Admin recovery is turned off.');
        }
        $written = $this->c->db()->write(function (): ?bool {
            if ($this->c->throttle->wait([self::START_BUCKET]) > 0) {
                return null;
            }
            $this->c->throttle->failure(self::START_BUCKET, self::LIMIT);

            return RecoveryCode::start($this->c->data->path(), $this->c->random, $this->c->clock->now());
        });
        if ($written !== null) {
            $this->c->audit->record(AuditAction::RECOVERY_STARTED, null, null, null, null, ['written' => $written]);
        }
    }

    /** Section 9.3. */
    public function recover(
        bool $enabled,
        string $username,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $again,
        string $clientAddress,
    ): RecoveryResult {
        // 1.
        if (!$enabled) {
            throw new NotFound('Admin recovery is turned off.');
        }

        // 2. Problems that depend only on what was typed.
        $problems = [];
        if ($password !== $again) {
            $problems[] = new Problem('password-again', 'problem.password.mismatch');
        }
        array_push($problems, ...PasswordPolicy::problems($password, $username));
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }

        // 3. The throttle, before the cost of a hash.
        $addressBucket = LoginThrottle::addressBucket($this->c->system, $clientAddress);
        $buckets = [$addressBucket, self::ATTEMPT_BUCKET];
        $wait = $this->c->db()->write(fn (): int => $this->c->throttle->wait($buckets));
        if ($wait > 0) {
            return new RecoveryResult(RecoveryResult::WAIT, $wait);
        }

        // 4. Always, before any lookup, so that every outcome costs the same.
        $hash = $this->c->hasher->hash($password);
        $this->c->seam('recovery.hashed');

        // 5. Check and change in one write.
        $dataPath = $this->c->data->path();
        [$wait, $person, $removed] = $this->c->db()->write(function () use ($buckets, $addressBucket, $dataPath, $username, $code, $hash): array {
            $wait = $this->c->throttle->wait($buckets);
            if ($wait > 0) {
                return [$wait, null, true];
            }
            $expected = RecoveryCode::current($dataPath, $this->c->clock->now());
            $person = $this->c->users->byUsername($username);
            $ok = $expected !== null && SetupCode::matches($expected, $code)
                && $person !== null && $person->active && $person->role === Role::Admin;
            if (!$ok) {
                $state = $this->c->throttle->failure(self::ATTEMPT_BUCKET, self::LIMIT);
                $this->c->throttle->failure($addressBucket, LoginThrottle::ADDRESS_LIMIT);
                $removed = true;
                if ($state->failures >= self::LIMIT) {
                    $removed = self::removeCode($dataPath);
                }

                return [0, null, $removed];
            }
            // The code goes first, so a second request with it, waiting for this write, finds none.
            $removed = self::removeCode($dataPath);
            $this->c->users->setPassword($person->id, $hash, false);
            $this->c->sessions->endAllFor($person->id);
            $this->c->throttle->clear(LoginThrottle::userBucket($this->c->system, Usernames::normalise($person->username)));
            $this->c->throttle->clear(self::ATTEMPT_BUCKET);

            return [0, $person, $removed];
        });
        if ($wait > 0) {
            return new RecoveryResult(RecoveryResult::WAIT, $wait);
        }

        // 6.
        if (!$removed) {
            error_log(self::NOT_REMOVED);
        }
        if ($person === null) {
            // 7.
            return new RecoveryResult(RecoveryResult::FAILED);
        }
        $this->c->audit->record(AuditAction::RECOVERED, null, null, 'user', $person->key, []);

        return new RecoveryResult(RecoveryResult::DONE);
    }

    /** Removes the code file; false (never an exception) when it cannot be removed, so the write goes on. */
    private static function removeCode(string $dataPath): bool
    {
        try {
            RecoveryCode::remove($dataPath);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }
}
