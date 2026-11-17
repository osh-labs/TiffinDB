<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;
use LogicException;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Auth\UserRecord;

/**
 * Everything a controller gets for one request (M1 spec part 2 section
 * 2.8): the request, the view, the core services, the signed-in person (if
 * any), links, the CSRF token pages print, cookies, and flash notices.
 * Controllers are constructed as `new Controller(Context $context)`.
 *
 * Session changes inside a request (P30, M1-WP2 review F9): a controller
 * that starts a session (sign-in, first Admin, own password change) passes
 * the new IssuedSession to withNewSession(), which sets the session cookie,
 * deletes the anonymous `tdb_csrf` cookie, ends the session the request's
 * old `tdb_session` cookie named (when it differs), and makes flash() write
 * to the new session. Call withNewSession() before flash().
 *
 * Flash notices are taken (and so cleared) only when a signed-in page is
 * rendered for a GET request with status 200, so a redirect, an error
 * page, or a HEAD request never swallows one.
 */
final class Context
{
    public const SESSION_COOKIE = 'tdb_session';
    public const CSRF_COOKIE = 'tdb_csrf';

    /** Session cookie lifetime: the absolute session lifetime (Sessions::ABSOLUTE_SECONDS). */
    public const SESSION_MAX_AGE = 2592000;

    private const NOTICE_KEY = '/^notice\.[a-z0-9_]+(?:[.-][a-z0-9_]+)*\z/';

    /** The session flash() writes to: one started in this request, else the request's own. */
    private ?string $flashToken;

    /**
     * @param string|null $sessionToken The request's `tdb_session` token when it resolved to $signedIn.
     * @param string|null $csrfToken    The token forms print: the session's, or the anonymous cookie's.
     * @param bool        $secureCookies Whether cookies carry the Secure attribute.
     */
    public function __construct(
        private readonly Request $request,
        private readonly View $view,
        private readonly Services $services,
        private readonly ?SignedIn $signedIn,
        private readonly UrlGenerator $urls,
        #[\SensitiveParameter] private readonly ?string $csrfToken,
        private readonly bool $secureCookies,
        #[\SensitiveParameter] private readonly ?string $sessionToken,
    ) {
        if (($signedIn === null) !== ($sessionToken === null)) {
            throw new InvalidArgumentException('A session token is given exactly when someone is signed in.');
        }
        $this->flashToken = $sessionToken;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function services(): Services
    {
        return $this->services;
    }

    public function view(): View
    {
        return $this->view;
    }

    public function signedIn(): ?SignedIn
    {
        return $this->signedIn;
    }

    /** @throws LogicException when nobody is signed in */
    public function actor(): Actor
    {
        if ($this->signedIn === null) {
            throw new LogicException('Nobody is signed in.');
        }

        return $this->signedIn->actor;
    }

    /** The request's session token when it resolved, else null. */
    public function sessionToken(): ?string
    {
        return $this->sessionToken;
    }

    /** @throws LogicException when the route has no CSRF token (only "/") */
    public function csrfToken(): string
    {
        if ($this->csrfToken === null) {
            throw new LogicException('This request has no CSRF token.');
        }

        return $this->csrfToken;
    }

    /**
     * The unescaped address of an application path (UrlGenerator::to).
     *
     * @param array<string, string> $query
     */
    public function url(string $path, array $query = []): string
    {
        return $this->urls->to($path, $query);
    }

    /**
     * A 303 to an application path.
     *
     * @param array<string, string> $query
     */
    public function redirect(string $path, array $query = []): Response
    {
        return Response::redirect($this->urls->to($path, $query));
    }

    /**
     * Stores a notice for the next signed-in page.
     *
     * @param array<string, string|int> $params
     * @throws LogicException when there is no session to carry it
     */
    public function flash(string $key, array $params = []): void
    {
        if (preg_match(self::NOTICE_KEY, $key) !== 1) {
            throw new InvalidArgumentException('A flash notice key must be a notice.* string key.');
        }
        if ($this->flashToken === null) {
            throw new LogicException('Only signed-in pages have flash notices.');
        }
        $this->services->setFlash($this->flashToken, $key, $params);
    }

    /**
     * Renders a page in the signed-in shell, or in the plain shell when
     * nobody is signed in. $nav names the current top-bar area.
     *
     * @param array<string, mixed> $data
     */
    public function page(string $template, array $data, int $status = 200, string $nav = ''): Response
    {
        if ($this->signedIn === null || $this->sessionToken === null) {
            return $this->plainPage($template, $data, $status);
        }
        $flash = null;
        if ($status === 200 && $this->request->method === 'GET') {
            $flash = $this->services->takeFlash($this->sessionToken);
            if ($flash !== null && preg_match(self::NOTICE_KEY, $flash['key']) !== 1) {
                $flash = null;
            }
        }
        $data['shell'] = self::shell($this->signedIn, $nav, $flash);

        return $this->view->page($template, $data, $status);
    }

    /**
     * Renders a page in the plain shell.
     *
     * @param array<string, mixed> $data
     */
    public function plainPage(string $template, array $data, int $status = 200): Response
    {
        $data['shell'] = null;

        return $this->view->page($template, $data, $status);
    }

    /**
     * The error page (title, one sentence, link to the start page) in the
     * shell that fits the request. Takes no flash notice.
     */
    public function errorPage(int $status, string $titleKey, string $bodyKey): Response
    {
        $data = [
            'status' => $status,
            'title' => $this->view->text($titleKey),
            'titleKey' => $titleKey,
            'bodyKey' => $bodyKey,
            'shell' => $this->signedIn === null ? null : self::shell($this->signedIn, '', null),
        ];

        return $this->view->page('error', $data, $status);
    }

    public function withCookie(Response $response, Cookie $cookie): Response
    {
        return $response->withCookie($cookie);
    }

    /** The session cookie for $session; null gives the cookie that deletes it. */
    public function sessionCookie(?IssuedSession $session): Cookie
    {
        if ($session === null) {
            return Cookie::deleting(self::SESSION_COOKIE, $this->cookiePath(), $this->secureCookies, 'Lax');
        }

        return new Cookie(
            self::SESSION_COOKIE,
            $session->token(),
            $this->cookiePath(),
            $this->secureCookies,
            'Lax',
            self::SESSION_MAX_AGE,
        );
    }

    /** The anonymous CSRF cookie for $token; null gives the cookie that deletes it. */
    public function csrfCookie(#[\SensitiveParameter] ?string $token): Cookie
    {
        if ($token === null) {
            return Cookie::deleting(self::CSRF_COOKIE, $this->cookiePath(), $this->secureCookies, 'Strict');
        }

        return new Cookie(self::CSRF_COOKIE, $token, $this->cookiePath(), $this->secureCookies, 'Strict', null);
    }

    /**
     * After a session has been started: sets its cookie, deletes the
     * anonymous CSRF cookie, ends the session named by the request's old
     * cookie when it is a different one, and sends later flash() calls to
     * the new session.
     */
    public function withNewSession(Response $response, IssuedSession $session): Response
    {
        $token = $session->token();
        $old = $this->request->cookie(self::SESSION_COOKIE);
        if ($old !== null && $old !== '' && !hash_equals($old, $token)) {
            $this->services->endSession($old);
        }
        $this->flashToken = $token;

        return $response->withCookie($this->sessionCookie($session))->withCookie($this->csrfCookie(null));
    }

    /** The cookie path: the encoded base path followed by `/`. */
    public function cookiePath(): string
    {
        return $this->urls->to('/');
    }

    /** The signed-in shell for $signedIn. */
    public static function shell(SignedIn $signedIn, string $nav, ?array $flash): Shell
    {
        $actor = $signedIn->actor;

        return new Shell($actor->displayName, self::initials($actor), $actor->isAdmin(), $nav, $flash);
    }

    /**
     * The person's initials by the one core rule (UserRecord::initials,
     * M1 spec part 1 section 6.3), applied to the actor's display name.
     */
    private static function initials(Actor $actor): string
    {
        $record = new UserRecord($actor->userId, $actor->userKey, '', $actor->displayName, $actor->role, true, false, '', null);

        return $record->initials();
    }
}
