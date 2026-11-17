<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use LogicException;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Change your password (docs/design/m1/sign-in-and-projects.md section 4).
 * Also the page a person who must change their password is sent to: the
 * kernel lets exactly this form, its POST, and sign-out through.
 *
 * No password is ever printed back. On success the core has ended every
 * session of the person and started a new one, whose cookie replaces the
 * old (Context::withNewSession).
 */
final class AccountController
{
    /** Core field names => element ids (section 4.1). */
    private const FIELDS = ['current' => 'account-current', 'password' => 'account-new'];

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /account/password. */
    public function passwordForm(Request $request): Response
    {
        return $this->render([], [], false, 200);
    }

    /** POST /account/password. */
    public function password(Request $request): Response
    {
        $new = $request->input('password');
        if ($new !== $request->input('repeat')) {
            // Checked here, before the core, which is then not called.
            $text = $this->context->view()->text('account.password.mismatch');

            return $this->render([['field' => 'account-repeat', 'text' => $text]], [], true, 422);
        }

        $token = $this->context->sessionToken();
        if ($token === null) {
            throw new LogicException('The password form needs a session.');
        }
        try {
            $issued = $this->context->services()->operations()->auth()->changeOwnPassword(
                $this->context->actor(),
                $token,
                $request->input('current'),
                $new,
            );
        } catch (ValidationFailed $e) {
            [$summary, $errors] = $this->problems($e->problems);

            return $this->render($summary, $errors, false, 422);
        }

        $response = $this->context->withNewSession($this->context->redirect('/projects'), $issued);
        $this->context->flash('notice.password_changed');

        return $response;
    }

    /**
     * @param list<array{field: ?string, text: string}> $problems
     * @param array<string, string>                     $errors element id => text
     */
    private function render(array $problems, array $errors, bool $mismatch, int $status): Response
    {
        $signedIn = $this->context->signedIn();

        return $this->context->page('account/password', [
            'title' => $this->context->view()->text('account.password.title'),
            'script' => 'show-password',
            'problems' => $problems,
            'errors' => $errors,
            'mismatch' => $mismatch,
            'forced' => $signedIn !== null && $signedIn->mustChangePassword,
        ], $status, 'account');
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
