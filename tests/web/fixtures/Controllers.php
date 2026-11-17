<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: controllers injected through the kernel's route-table
 * parameter. Loaded with require_once by the tests that use them.
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web\Fixtures;

use RuntimeException;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

final class FixtureController
{
    public const SECRET_MESSAGE = 'Controller failure reading /fixture/secret/config.php';

    /** The token of the session newSession() starts (synthetic, well-formed). */
    public const STARTED_TOKEN = 'startedCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';

    /** Theme name => query for its preview link, in display order (the M0 start page). */
    private const PREVIEW_THEMES = [
        'light' => [],
        'dark' => ['theme' => 'dark'],
        'hc-light' => ['theme' => 'hc-light'],
        'hc-dark' => ['theme' => 'hc-dark'],
    ];

    public function __construct(private readonly Context $context)
    {
    }

    /**
     * The M0 start page with its theme preview links, kept as a fixture so
     * that the development-only appearance preview (appearance_preview
     * true) can still be tested through the kernel now that `/` redirects.
     */
    public function home(Request $request): Response
    {
        $view = $this->context->view();
        $preview = null;
        if ($view->config('appearance_preview') === true) {
            $current = $view->appearance()->theme();
            $preview = [];
            foreach (self::PREVIEW_THEMES as $theme => $query) {
                $preview[] = ['path' => '/', 'query' => $query, 'label' => 'home.preview.' . $theme, 'current' => $theme === $current];
            }
        }

        return $this->context->plainPage('home', ['title' => null, 'preview' => $preview]);
    }

    public function throws(Request $request): Response
    {
        throw new RuntimeException(self::SECRET_MESSAGE);
    }

    public function partialFailure(Request $request): Response
    {
        return $this->context->plainPage('partial-failure', []);
    }

    public function warning(Request $request): Response
    {
        return $this->context->plainPage('warning', []);
    }

    public function notAResponse(Request $request): string
    {
        return 'not a response';
    }

    public function badTemplateName(Request $request): Response
    {
        return $this->context->plainPage('../layout', []);
    }

    /** @param array<string, string> $params */
    public function params(Request $request, array $params): Response
    {
        return self::text('id=' . ($params['id'] ?? ''));
    }

    public function setsHeaders(Request $request): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'content-security-policy' => "default-src *",
            'X-Frame-Options' => 'ALLOWALL',
        ], 'ok');
    }

    /** What the controller was given, as text. */
    public function show(Request $request): Response
    {
        $signedIn = $this->context->signedIn();
        $csrf = 'none';
        try {
            $csrf = $this->context->csrfToken();
        } catch (\LogicException) {
        }

        return self::text(sprintf(
            "handled %s\nuser=%s\ncsrf=%s\ninput=%s",
            $request->method,
            $signedIn === null ? 'none' : $signedIn->actor->userKey,
            $csrf,
            $request->input('name'),
        ));
    }

    /** A signed-in page through Context::page() (the real error template). */
    public function page(Request $request): Response
    {
        return $this->context->page('error', [
            'status' => 200,
            'title' => 'Fixture page',
            'titleKey' => 'error.404.title',
            'bodyKey' => 'error.404.body',
        ], 200, 'projects');
    }

    public function flashAndRedirect(Request $request): Response
    {
        $this->context->flash('notice.password_changed');

        return $this->context->redirect('/done');
    }

    public function newSession(Request $request): Response
    {
        $session = new IssuedSession(
            self::STARTED_TOKEN,
            new SessionRecord(str_repeat('1', 64), 2, FakeServices::NEW_CSRF, '2026-11-03T10:00:00Z', '2026-12-03T10:00:00Z'),
        );
        $response = $this->context->withNewSession($this->context->redirect('/projects'), $session);
        $this->context->flash('notice.password_changed');

        return $response;
    }

    public function signOut(Request $request): Response
    {
        return $this->context->withCookie(
            $this->context->redirect('/sign-in', ['signed-out' => '1']),
            $this->context->sessionCookie(null),
        );
    }

    public function notFound(Request $request): Response
    {
        throw new NotFound();
    }

    public function accessDenied(Request $request): Response
    {
        throw new AccessDenied();
    }

    public function busy(Request $request): Response
    {
        throw new Busy();
    }

    /** @param array<string, string> $params */
    public function unavailable(Request $request, array $params): Response
    {
        throw new Unavailable($params['reason'] ?? '');
    }

    public function validationFailed(Request $request): Response
    {
        throw new ValidationFailed([new Problem('name', 'problem.name.empty')]);
    }

    private static function text(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}
