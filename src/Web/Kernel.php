<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use ErrorException;
use LogicException;
use Throwable;
use UnexpectedValueException;

/**
 * Turns a Request into a Response (M0 spec section 6.5).
 *
 * The kernel reads templates from `<projectRoot>/templates` and strings
 * from `<projectRoot>/strings/en.php`, and nothing else from disk. The
 * project root and the route table are the only test seams; nothing in a
 * request can influence either.
 *
 * Controllers are constructed as `new Controller(View $view)` and their
 * method is called with the Request and the route parameters.
 *
 * Error pages render the `error` page template with this data: `status`
 * (int), `title` (plain-text page title), `titleKey` and `bodyKey` (the
 * string keys `error.<status>.title` and `error.<status>.body`).
 */
final class Kernel
{
    /** Methods the application accepts in M0. M1 adds POST with CSRF. */
    private const ACCEPTED_METHODS = ['GET', 'HEAD'];

    private const FALLBACK_BODY = "Something went wrong. TiffinDB could not show this page.\n";

    private readonly SecurityHeaders $securityHeaders;

    /**
     * @param array<string, mixed>   $config Configuration (config/app.php).
     * @param list<mixed>|null       $routes Route table in the routes.php
     *                                       format; null for the real one.
     */
    public function __construct(
        private readonly array $config,
        private readonly string $projectRoot,
        private readonly ?array $routes = null,
    ) {
        $this->securityHeaders = new SecurityHeaders();
    }

    public function handle(Request $request): Response
    {
        // Any PHP warning or notice during handling becomes an exception, so
        // that it ends in the generic 500 page rather than a broken page.
        // This deliberately ignores error_reporting(): the suppression
        // operator is not used in this code base (ADR 0008 decision 6).
        // Deprecations go on to the previous handler.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }, E_ALL & ~(E_DEPRECATED | E_USER_DEPRECATED));

        try {
            $view = null;
            try {
                $view = $this->view($request);
                $response = $this->dispatch($request, $view);
            } catch (Throwable $e) {
                self::log($e);
                $response = $this->serverError($view);
            }
        } finally {
            restore_error_handler();
        }

        return $this->securityHeaders->apply($response);
    }

    private function dispatch(Request $request, View $view): Response
    {
        if ($request->malformed) {
            return $this->errorPage($view, 400);
        }
        if (!in_array($request->method, self::ACCEPTED_METHODS, true)) {
            return $this->errorPage($view, 405)->withHeader('Allow', implode(', ', self::ACCEPTED_METHODS));
        }

        $router = new Router($this->routes ?? Router::applicationRoutes());
        $match = $router->match($request->method, $request->path);

        if ($match->isNotFound()) {
            return $this->errorPage($view, 404);
        }
        if ($match->isMethodNotAllowed()) {
            return $this->errorPage($view, 405)->withHeader('Allow', implode(', ', $match->allowedMethods));
        }

        /** @var array{0: class-string, 1: string} $handler */
        $handler = $match->handler;
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            throw new LogicException("Route handler class not found: {$class}");
        }
        $controller = new $class($view);
        if (!is_callable([$controller, $method])) {
            throw new LogicException("Route handler method not callable: {$class}::{$method}");
        }

        $response = $controller->$method($request, $match->params);
        if (!$response instanceof Response) {
            throw new LogicException("Route handler did not return a Response: {$class}::{$method}");
        }

        return $response;
    }

    private function view(Request $request): View
    {
        $version = $this->config['version'] ?? null;
        $sourceUrl = $this->config['source_url'] ?? null;
        $preview = $this->config['appearance_preview'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new UnexpectedValueException('Configuration: version must be a non-empty string.');
        }
        if (!is_string($sourceUrl) || preg_match('#^https://[^\s"<>\\\\]+$#', $sourceUrl) !== 1) {
            throw new UnexpectedValueException('Configuration: source_url must be an https URL.');
        }
        if (!is_bool($preview)) {
            throw new UnexpectedValueException('Configuration: appearance_preview must be a boolean.');
        }

        $appearance = $preview ? Appearance::fromQuery($request->query) : Appearance::default();

        return new View(
            $this->projectRoot . '/templates',
            new Strings($this->projectRoot . '/strings/en.php'),
            new UrlGenerator($request->basePath, $version),
            $appearance,
            $this->config,
        );
    }

    private function errorPage(View $view, int $status): Response
    {
        $titleKey = "error.{$status}.title";

        return $view->page('error', [
            'status' => $status,
            'title' => $view->text($titleKey),
            'titleKey' => $titleKey,
            'bodyKey' => "error.{$status}.body",
        ], $status);
    }

    /** The 500 page, or a fixed plain-text 500 if that cannot be rendered either. */
    private function serverError(?View $view): Response
    {
        if ($view !== null) {
            try {
                return $this->errorPage($view, 500);
            } catch (Throwable $e) {
                self::log($e);
            }
        }

        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], self::FALLBACK_BODY);
    }

    /**
     * One line to the server's error log: class, message, file, and line.
     * No request data; never part of the response.
     */
    private static function log(Throwable $e): void
    {
        $line = sprintf('TiffinDB: %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        error_log((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line));
    }
}
