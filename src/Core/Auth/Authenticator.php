<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;

/**
 * Sign-in, resuming a session, and sign-out (M1 spec section 6.7).
 *
 * signIn() opens one write() around the throttle check, the password
 * check, and the throttle count or session it leads to (P51), so parallel
 * guesses are counted one after another. Audit entries are written after
 * that transaction commits. They carry the failure reason and, when the
 * username matched a person, that person's id and key; never the
 * attempted username, the password, the address, or a token.
 *
 * Every failure reason (unknown username, wrong password, inactive
 * person) gives the caller the same result, and each costs one password
 * verification: an unknown username is checked against the hasher's dummy
 * hash. A throttled attempt checks no password.
 *
 * A new session is started at every successful sign-in. Ending the
 * session the browser held before (rotation on sign-in) is the caller's
 * part, since only the caller has its cookie; ending all of a person's
 * sessions on a password, role, or active change is the operation's part
 * (Sessions::endAllFor).
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

        /** @var array{0: SignInResult, 1: string, 2: ?UserRecord, 3: ?string} $outcome */
        $outcome = $this->s->db()->write(function (Database $db) use ($username, $password, $userBucket, $addressBucket): array {
            $wait = $this->throttle->wait([$userBucket, $addressBucket]);
            if ($wait > 0) {
                $person = $this->users->byUsername($username);

                return [new SignInResult(SignInOutcome::Throttled, null, $wait), AuditAction::SIGN_IN_FAILED, $person, AuditAction::REASON_THROTTLED];
            }

            $person = $this->users->byUsername($username);
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
                }
            }

            if ($reason !== null) {
                $this->throttle->failure($userBucket, LoginThrottle::USER_LIMIT);
                $this->throttle->failure($addressBucket, LoginThrottle::ADDRESS_LIMIT);

                return [new SignInResult(SignInOutcome::Invalid), AuditAction::SIGN_IN_FAILED, $person, $reason];
            }

            /** @var UserRecord $person */
            $this->throttle->clear($userBucket);
            if ($this->hasher->needsRehash($hash)) {
                $this->users->replaceHash($person->id, $this->hasher->hash($password));
            }
            $this->users->markSignedIn($person->id);
            $session = $this->sessions->start($person->id);

            return [
                new SignInResult(SignInOutcome::Success, $session, 0, $person->mustChangePassword),
                AuditAction::SIGN_IN,
                $person,
                null,
            ];
        });

        [$result, $action, $person, $reason] = $outcome;
        $this->audit->record(
            $action,
            $person?->id,
            null,
            $person === null ? null : 'user',
            $person?->key,
            $reason === null ? [] : ['reason' => $reason],
        );

        return $result;
    }

    /** The actor and session for a cookie token, or null. */
    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
    {
        if (!Sessions::wellFormed($token)) {
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

            return new SignedIn(
                new Actor($person->id, $person->key, $person->role, $person->displayName),
                $session,
                $person->mustChangePassword,
            );
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
}
