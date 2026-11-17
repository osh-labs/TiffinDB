<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Auth\SignInResult;
use TiffinDB\Core\Auth\ThrottleState;
use TiffinDB\Core\Auth\Usernames;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\System\AuditAction;

/**
 * Signing in and out, the person's own password, the flash notice, and
 * permission questions from the web layer (M1 spec section 9.2).
 */
final class AuthOperations
{
    public function __construct(private readonly Context $c)
    {
    }

    public function signIn(string $username, #[\SensitiveParameter] string $password, string $clientAddress): SignInResult
    {
        return $this->c->authenticator->signIn($username, $password, $clientAddress);
    }

    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
    {
        return $this->c->authenticator->resume($token);
    }

    /** Ends the session when it exists; Authenticator records auth.sign_out itself (P66). */
    public function signOut(#[\SensitiveParameter] string $token): void
    {
        $this->c->authenticator->signOut($token);
    }

    /**
     * Changes the acting person's own password. $token must be the
     * person's current session. A wrong current password counts as a
     * failure on the person's throttle bucket, as a failed sign-in does;
     * a blocked bucket checks nothing and gives `current`:
     * `problem.throttled`. The shape follows sign-in (P68): a short write
     * that checks the throttle and counts a failure in advance, the
     * password check with no transaction open, and a short write that
     * settles. On success every session of the person ends and a new one
     * is started and returned.
     */
    public function changeOwnPassword(
        Actor $actor,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $current,
        #[\SensitiveParameter] string $new,
    ): IssuedSession {
        $person = $this->c->users->byId($actor->userId);
        if ($person === null || $person->key !== $actor->userKey) {
            throw new AccessDenied('The acting person does not exist.');
        }
        $bucket = LoginThrottle::userBucket($this->c->system, Usernames::normalise($person->username));

        // (a) The session must be the actor's; check the throttle; count a failure in advance.
        /** @var array{0: int, 1: ?ThrottleState, 2: ?ThrottleState} $advance */
        $advance = $this->c->db()->write(function () use ($actor, $token, $bucket): array {
            $this->c->requireCurrentPerson($actor);
            $session = $this->c->sessions->resolve($token);
            if ($session === null || $session->userId !== $actor->userId) {
                throw new AccessDenied('The session is not the acting person\'s.');
            }
            $wait = $this->c->throttle->wait([$bucket]);
            if ($wait > 0) {
                return [$wait, null, null];
            }
            $before = $this->c->throttle->state($bucket);

            return [0, $before, $this->c->throttle->failure($bucket, LoginThrottle::USER_LIMIT)];
        });
        [$wait, $before, $counted] = $advance;
        if ($wait > 0) {
            throw new ValidationFailed([new Problem('current', 'problem.throttled', ['seconds' => $wait])]);
        }
        assert($counted instanceof ThrottleState);

        // (b) Check the current password and the new one, with no transaction open.
        $hash = $this->c->users->passwordHash($person->id);
        $currentRight = $this->c->hasher->verify($current, $hash);
        $problems = $currentRight ? [] : [new Problem('current', 'problem.password.current_wrong')];
        $problems = [...$problems, ...PasswordPolicy::problems($new, $person->username, 'password')];
        if ($problems !== []) {
            if ($currentRight) {
                // Only the new password was refused: the current one was right, so it is no failure.
                $this->c->db()->write(function () use ($bucket, $before, $counted): void {
                    $this->c->throttle->takeBack($bucket, LoginThrottle::USER_LIMIT, $before, $counted);
                });
            }

            throw new ValidationFailed($problems);
        }
        $newHash = $this->c->hasher->hash($new);

        // (c) Settle: same person, same hash as checked; store, end every session, start a new one.
        $issued = $this->c->db()->write(function () use ($actor, $hash, $newHash, $bucket): IssuedSession {
            $this->c->requireCurrentPerson($actor);
            if (!hash_equals($hash, $this->c->users->passwordHash($actor->userId))) {
                // The password was changed while it was being checked.
                throw new ValidationFailed([new Problem('current', 'problem.password.current_wrong')]);
            }
            $this->c->throttle->clear($bucket);
            $this->c->users->setPassword($actor->userId, $newHash, false);
            $this->c->sessions->endAllFor($actor->userId);

            return $this->c->sessions->start($actor->userId);
        });
        $this->c->audit->record(AuditAction::PASSWORD_CHANGED, $actor->userId, null, 'user', $actor->userKey);

        return $issued;
    }

    /** @param array<string, string|int> $params */
    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void
    {
        $this->c->db()->write(function () use ($token, $key, $params): void {
            $this->c->sessions->setFlash($token, $key, $params);
        });
    }

    /** @return array{key: string, params: array<string, mixed>}|null */
    public function takeFlash(#[\SensitiveParameter] string $token): ?array
    {
        return $this->c->db()->write(fn (): ?array => $this->c->sessions->takeFlash($token));
    }

    /**
     * Whether the actor has the permission (on the project, for a
     * project-scoped one); false for an unknown project. Lets the web layer
     * choose which controls to show without deciding policy itself.
     */
    public function can(Actor $actor, Permission $permission, ?string $projectKey = null): bool
    {
        if ($permission->isInstanceWide()) {
            return $this->c->authorizer->can($actor, $permission);
        }
        if ($projectKey === null) {
            throw new \InvalidArgumentException('A project-scoped permission needs a project.');
        }
        $project = $this->c->registry->byKey($projectKey);

        return $project !== null && $this->c->authorizer->can($actor, $permission, $project);
    }

    /** Authorizer::require after looking the project up; an unknown project is NotFound. */
    public function require(Actor $actor, Permission $permission, ?string $projectKey = null): void
    {
        if ($permission->isInstanceWide()) {
            $this->c->authorizer->require($actor, $permission);

            return;
        }
        if ($projectKey === null) {
            throw new \InvalidArgumentException('A project-scoped permission needs a project.');
        }
        $this->c->project($actor, $permission, $projectKey);
    }
}
