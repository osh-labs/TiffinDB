<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;

/**
 * Sign-in, resuming a session, and sign-out (M1 spec section 6.7).
 *
 * signIn() runs in three steps (P68, which amends P51 for sign-in only),
 * so that the write lock is never held while a password is checked:
 *
 * (a) one short write(): the throttle check; when not blocked, a failure
 *     is counted in advance on the user bucket and the address bucket.
 *     Parallel guesses are therefore counted one after another, and none
 *     can be verified once the earlier ones have reached the limit;
 * (b) with no transaction open: one password verification, against the
 *     person's hash or, for an unknown username, the dummy hash;
 * (c) on success, a second short write(): the person is read again and
 *     must still be active with the same password hash; then the user
 *     bucket is cleared, the advance count on the address bucket taken
 *     back (the row restored exactly when no other failure came in
 *     between), the hash replaced when it needs a rehash (the new hash is made
 *     in step b), and the session started. On failure nothing more is
 *     written: the failure was counted in step a.
 *
 * Audit entries are written after the transactions commit. They carry the
 * failure reason and, when the username matched a person, that person's
 * id and key; never the attempted username, the password, the address,
 * or a token.
 *
 * Every failure reason (unknown username, wrong password, inactive
 * person) gives the caller the same result, and each costs exactly one
 * password verification. A throttled attempt checks no password.
 *
 * A new session is started at every successful sign-in. Ending the
 * session the browser held before (rotation on sign-in) is the caller's
 * part, since only the caller has its cookie; ending all of a person's
 * sessions on a password, role, or active change is the operation's part
 * (Sessions::endAllFor).
 *
 * resume() reads first and opens write() only when the session must be
 * touched or deleted (P69); signOut() writes its own auth.sign_out entry.
 */
final class Authenticator
{
    public function __construct(
        private readonly SystemDatabase $s,
        private readonly Users $users,
        private readonly Sessions $sessions,
        private readonly LoginThrottle $throttle,
        private readonly PasswordHasher $hasher,
        private readonly AuditLog $audit,
    ) {
    }

    public function signIn(string $username, #[\SensitiveParameter] string $password, string $clientAddress): SignInResult
    {
        $userBucket = LoginThrottle::userBucket($this->s, Usernames::normalise($username));
        $addressBucket = LoginThrottle::addressBucket($this->s, $clientAddress);

        // (a) Check the throttle and count a failure in advance, keeping the address row's
        // state from before, so that a success can put it back exactly.
        /** @var array{0: int, 1: ?ThrottleState, 2: ?ThrottleState} $advance */
        $advance = $this->s->db()->write(function () use ($userBucket, $addressBucket): array {
            $wait = $this->throttle->wait([$userBucket, $addressBucket]);
            if ($wait > 0) {
                return [$wait, null, null];
            }
            $this->throttle->failure($userBucket, LoginThrottle::USER_LIMIT);
            $before = $this->throttle->state($addressBucket);
            $counted = $this->throttle->failure($addressBucket, LoginThrottle::ADDRESS_LIMIT);

            return [0, $before, $counted];
        });
        [$wait, $addressBefore, $addressCounted] = $advance;
        if ($wait > 0) {
            $person = $this->users->byUsername($username);
            $this->recordFailure($person, AuditAction::REASON_THROTTLED);

            return new SignInResult(SignInOutcome::Throttled, null, $wait);
        }

        // (b) Check the password with no transaction open.
        $person = $this->users->byUsername($username);
        $hash = null;
        $newHash = null;
        if ($person === null) {
            $this->hasher->verifyDummy($password);
            $reason = AuditAction::REASON_UNKNOWN_USER;
        } else {
            $hash = $this->users->passwordHash($person->id);
            $reason = null;
            if (!$this->hasher->verify($password, $hash)) {
                $reason = AuditAction::REASON_BAD_PASSWORD;
            } elseif (!$person->active) {
                $reason = AuditAction::REASON_INACTIVE;
            } elseif ($this->hasher->needsRehash($hash)) {
                $newHash = $this->hasher->hash($password);
            }
        }
        if ($person === null || $hash === null || $reason !== null) {
            $this->recordFailure($person, $reason ?? AuditAction::REASON_UNKNOWN_USER);

            return new SignInResult(SignInOutcome::Invalid);
        }

        // (c) Re-check and complete the sign-in.
        $id = $person->id;
        /** @var array{0: ?UserRecord, 1: ?IssuedSession, 2: string} $outcome */
        $outcome = $this->s->db()->write(function () use ($id, $hash, $newHash, $userBucket, $addressBucket, $addressBefore, $addressCounted): array {
            $current = $this->users->byId($id);
            if ($current === null || !$current->active) {
                return [$current, null, AuditAction::REASON_INACTIVE];
            }
            if (!hash_equals($hash, $this->users->passwordHash($id))) {
                // The password was changed while it was being checked.
                return [$current, null, AuditAction::REASON_BAD_PASSWORD];
            }
            $this->throttle->clear($userBucket);
            if ($addressCounted !== null) {
                $this->throttle->takeBack($addressBucket, LoginThrottle::ADDRESS_LIMIT, $addressBefore, $addressCounted);
            }
            if ($newHash !== null) {
                $this->users->replaceHash($id, $newHash);
            }
            $this->users->markSignedIn($id);

            return [$current, $this->sessions->start($id), ''];
        });
        [$current, $session, $lateReason] = $outcome;
        if ($session === null) {
            $this->recordFailure($current ?? $person, $lateReason);

            return new SignInResult(SignInOutcome::Invalid);
        }
        /** @var UserRecord $current */
        $this->audit->record(AuditAction::SIGN_IN, $current->id, null, 'user', $current->key);

        return new SignInResult(SignInOutcome::Success, $session, 0, $current->mustChangePassword);
    }

    /**
     * The actor and session for a cookie token, or null. A fresh, valid
     * session is found with plain reads; write() is opened only when the
     * session must be touched or deleted, and everything is checked again
     * inside it.
     */
    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
    {
        if (!Sessions::wellFormed($token)) {
            return null;
        }
        $lookup = $this->sessions->lookup($token);
        if ($lookup->record !== null) {
            $person = $this->users->byId($lookup->record->userId);
            if ($person !== null && $person->active) {
                return self::signedIn($person, $lookup->record);
            }
        } elseif (!$lookup->needsWrite) {
            return null;
        }

        return $this->s->db()->write(function () use ($token): ?SignedIn {
            $session = $this->sessions->resolve($token);
            if ($session === null) {
                return null;
            }
            $person = $this->users->byId($session->userId);
            if ($person === null || !$person->active) {
                $this->sessions->end($token);

                return null;
            }

            return self::signedIn($person, $session);
        });
    }

    /**
     * Ends the session for this token when it is live, and then records
     * auth.sign_out for its person. Nothing happens, and nothing is
     * recorded, for an unknown or expired token.
     */
    public function signOut(#[\SensitiveParameter] string $token): void
    {
        if (!Sessions::wellFormed($token)) {
            return;
        }
        $person = $this->s->db()->write(function () use ($token): ?UserRecord {
            $session = $this->sessions->resolve($token);
            if ($session === null) {
                return null;
            }
            $this->sessions->end($token);

            return $this->users->byId($session->userId);
        });
        if ($person !== null) {
            $this->audit->record(AuditAction::SIGN_OUT, $person->id, null, 'user', $person->key);
        }
    }

    private static function signedIn(UserRecord $person, SessionRecord $session): SignedIn
    {
        return new SignedIn(
            new Actor($person->id, $person->key, $person->role, $person->displayName),
            $session,
            $person->mustChangePassword,
        );
    }

    private function recordFailure(?UserRecord $person, string $reason): void
    {
        $this->audit->record(
            AuditAction::SIGN_IN_FAILED,
            $person?->id,
            null,
            $person === null ? null : 'user',
            $person?->key,
            ['reason' => $reason],
        );
    }
}
