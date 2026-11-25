<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\RecoveryResult;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Admin recovery for signed-out visitors (docs/design/m7/recovery.md; M7
 * screens spec section 9). The routes are `guest` (a signed-in person is
 * sent away by the kernel) and every POST carries the guest CSRF token.
 *
 * No answer says whether a username exists, is an Admin, or is active, or
 * whether a code file was written: starting always answers the same 303,
 * and every `failed` outcome renders the same page (the typed username is
 * not put back, so the bytes do not depend on it). The code and the
 * passwords are never printed back. With `admin_recovery` off every route
 * is the 404 page, before the core is called. The client address given
 * to the core is Request::$clientAddress, which is REMOTE_ADDR only; no
 * forwarded header is ever read (WP2 security review).
 */
final class RecoveryController
{
    /** Core field names => element ids. */
    private const FIELDS = [
        'username' => 'recover-username',
        'code' => 'recover-code',
        'password' => 'recover-password',
        'password-again' => 'recover-again',
    ];

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /recover, optionally with `?started=1`. */
    public function form(Request $request): Response
    {
        $this->requireEnabled();
        $started = ($request->query['started'] ?? null) === '1';

        return $this->render($started, '', [], [], null, 200);
    }

    /** POST /recover/start: 303 to `/recover?started=1` whatever happened. */
    public function start(Request $request): Response
    {
        $this->requireEnabled();
        $this->context->services()->operations()->recovery()->start($this->context->adminRecovery(), $request->clientAddress);

        return $this->context->redirect('/recover', ['started' => '1']);
    }

    /** POST /recover. */
    public function recover(Request $request): Response
    {
        $this->requireEnabled();
        $username = $request->input('username');
        try {
            $result = $this->context->services()->operations()->recovery()->recover(
                $this->context->adminRecovery(),
                $username,
                $request->input('code'),
                $request->input('password'),
                $request->input('password_again'),
                $request->clientAddress,
            );
        } catch (ValidationFailed $e) {
            [$summary, $errors] = $this->problems($e->problems);

            return $this->render(false, $username, $summary, $errors, null, 422);
        }

        if ($result->outcome === RecoveryResult::DONE) {
            return $this->context->redirect('/sign-in', ['recovered' => '1']);
        }
        if ($result->outcome === RecoveryResult::WAIT) {
            $wait = max(1, $result->waitSeconds);
            $text = $this->context->view()->format()->wait($wait);

            return $this->render(false, '', [], [], $text, 429)->withHeader('Retry-After', (string) $wait);
        }
        $text = $this->context->view()->format()->problemText(RecoveryResult::FAILED_KEY, []);

        return $this->render(false, '', [['field' => 'recover-code', 'text' => $text]], ['recover-code' => $text], null, 422);
    }

    private function requireEnabled(): void
    {
        if (!$this->context->adminRecovery()) {
            throw new NotFound('Admin recovery is turned off.');
        }
    }

    /**
     * @param list<array{field: ?string, text: string}> $problems
     * @param array<string, string>                     $errors element id => text
     * @param string|null                               $wait   the formatted waiting time when throttled
     */
    private function render(bool $started, string $username, array $problems, array $errors, ?string $wait, int $status): Response
    {
        return $this->context->plainPage('recover/index', [
            'title' => $this->context->view()->text('recovery.title'),
            'script' => 'show-password',
            'started' => $started,
            'username' => $username,
            'problems' => $problems,
            'errors' => $errors,
            'wait' => $wait,
            'errorTitle' => $wait !== null,
        ], $status);
    }

    /**
     * The summary and the per-field errors for core problems.
     *
     * @param list<Problem> $problems
     * @return array{0: list<array{field: ?string, text: string}>, 1: array<string, string>}
     */
    private function problems(array $problems): array
    {
        $format = $this->context->view()->format();
        $summary = [];
        $errors = [];
        foreach ($problems as $problem) {
            $text = $format->problem($problem);
            $id = $problem->field === null ? null : (self::FIELDS[$problem->field] ?? null);
            $summary[] = ['field' => $id, 'text' => $text];
            if ($id !== null && !isset($errors[$id])) {
                $errors[$id] = $text;
            }
        }

        return [$summary, $errors];
    }
}
