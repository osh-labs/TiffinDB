<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Sign in and sign out (docs/design/m1/sign-in-and-projects.md section 2;
 * M1 spec part 2 section 3.1).
 *
 * The page a failed attempt gets depends only on the core's outcome
 * (Invalid or Throttled, and the wait), never on whether the username
 * exists, so unknown and known usernames get the same answer. The
 * password is never printed back.
 */
final class SignInController
{
    public function __construct(private readonly Context $context)
    {
    }

    /** GET /sign-in, optionally with `?signed-out=1`. */
    public function form(Request $request): Response
    {
        $signedOut = ($request->query['signed-out'] ?? null) === '1';

        return $this->render($signedOut, '', null, null, 200);
    }

    /** POST /sign-in. */
    public function submit(Request $request): Response
    {
        $username = $request->input('username');
        $result = $this->context->services()->operations()->auth()->signIn(
            $username,
            $request->input('password'),
            $request->clientAddress,
        );

        if ($result->outcome === SignInOutcome::Success && $result->session !== null) {
            $response = $this->context->redirect($result->mustChangePassword ? '/account/password' : '/projects');

            return $this->context->withNewSession($response, $result->session);
        }
        if ($result->outcome === SignInOutcome::Throttled) {
            $wait = max(1, $result->waitSeconds);
            $text = $this->context->view()->format()->problemText('problem.throttled', ['seconds' => $wait]);

            return $this->render(false, $username, 'throttled', $text, 429)
                ->withHeader('Retry-After', (string) $wait);
        }

        return $this->render(false, $username, 'invalid', null, 422);
    }

    /** POST /sign-out: ends the session (the core records auth.sign_out) and deletes the cookie. */
    public function signOut(Request $request): Response
    {
        $token = $this->context->sessionToken();
        if ($token !== null) {
            $this->context->services()->operations()->auth()->signOut($token);
        }
        $response = $this->context->redirect('/sign-in', ['signed-out' => '1']);

        return $response->withCookie($this->context->sessionCookie(null));
    }

    /** @param 'invalid'|'throttled'|null $error */
    private function render(bool $signedOut, string $username, ?string $error, ?string $throttledText, int $status): Response
    {
        return $this->context->plainPage('sign-in', [
            'title' => $this->context->view()->text('signin.title'),
            'errorTitle' => $error !== null,
            'script' => 'show-password',
            'signedOut' => $signedOut,
            'username' => $username,
            'error' => $error,
            'throttledText' => $throttledText,
        ], $status);
    }
}
