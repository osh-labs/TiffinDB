<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Throwable;

/**
 * Renders plain PHP templates (ADR 0008 decision 4; M0 spec section 6.8).
 *
 * page() renders `templates/pages/<name>.php`, then `templates/layout.php`
 * with the page markup in the `content` slot. Each template runs with
 * `$this` bound to a ViewScope (the helpers) and `$data` (the array it was
 * given); nothing else is in scope.
 *
 * Data contract with the templates (M1 spec part 2 section 4):
 *
 * - The page template receives the controller's `$data`, except that a
 *   Shell in `shell` is replaced by Shell::toArray() (templates cannot call
 *   methods on data).
 * - The layout receives the same `$data` plus three keys the view sets and
 *   a controller cannot override: `sourceUrl` (the configured "Source code"
 *   link target), `documentTitle` (the plain-text document title, "TiffinDB"
 *   or "<title> - TiffinDB"), and `hasError` (true when `problems` is a
 *   non-empty list or `errorTitle` is true; the title then reads
 *   "Error: <documentTitle>").
 * - Page data keys the layout reads, all optional: `title` (plain text, or
 *   null for "TiffinDB" alone), `shell` (a Shell for the signed-in shell;
 *   absent or null for the plain shell), `wide` (true: the plain shell's
 *   main is not the centred column), `script` (`show-password` adds the one
 *   script), `breadcrumb` (list of {label, path|null}), `problems` (list of
 *   {field: element id|null, text: plain text} for the error summary), and
 *   `errorTitle` (true: error page title without a summary, for sign-in).
 * - A partial receives only the array passed to partial().
 *
 * The CSRF token (for the csrf() helper) and the Format (for count()) are
 * set with withCsrfToken() and withFormat().
 *
 * Template names are fixed strings written in code and must match
 * NAME_PATTERN. They are never derived from request data.
 */
final class View
{
    public const NAME_PATTERN = '#^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)*\z#';

    /** A CSRF token as issued by the core: 1 to 128 characters of [A-Za-z0-9_-]. */
    private const CSRF_TOKEN = '/^[A-Za-z0-9_-]{1,128}\z/';

    private readonly Format $format;

    /**
     * @param array<string, mixed> $config
     * @param string|null          $csrfToken The token forms print (csrf()); null when none is known.
     * @param Format|null          $format    Null gives a Format in UTC whose clock refuses to run
     *                                        (count() works; relative dates need withFormat()).
     */
    public function __construct(
        private readonly string $templateDir,
        private readonly Strings $strings,
        private readonly UrlGenerator $urls,
        private readonly Appearance $appearance,
        private readonly array $config,
        private readonly ?string $csrfToken = null,
        ?Format $format = null,
    ) {
        if ($csrfToken !== null && preg_match(self::CSRF_TOKEN, $csrfToken) !== 1) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
        $this->format = $format ?? new Format(
            $strings,
            new DateTimeZone('UTC'),
            static function (): DateTimeImmutable {
                throw new LogicException('No clock was given to the view.');
            },
        );
    }

    /** The same view, printing this CSRF token in forms. */
    public function withCsrfToken(string $token): self
    {
        return new self($this->templateDir, $this->strings, $this->urls, $this->appearance, $this->config, $token, $this->format);
    }

    /** The same view with this Format (time zone and clock). */
    public function withFormat(Format $format): self
    {
        return new self($this->templateDir, $this->strings, $this->urls, $this->appearance, $this->config, $this->csrfToken, $format);
    }

    /** @param array<string, mixed> $data */
    public function page(string $template, array $data, int $status = 200): Response
    {
        $scope = new ViewScope($this, $this->strings, $this->urls, $this->appearance);

        $shell = $data['shell'] ?? null;
        if ($shell !== null && !$shell instanceof Shell) {
            throw new InvalidArgumentException('The shell data key must hold a Shell or null.');
        }
        $data['shell'] = $shell?->toArray();

        $content = $this->render('pages/' . self::checkName($template), $scope, $data);
        $scope->setSlot('content', $content);

        $sourceUrl = $this->config['source_url'] ?? null;
        if (!is_string($sourceUrl)) {
            throw new LogicException('source_url is not configured.');
        }
        $title = $data['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            throw new InvalidArgumentException('The page title must be a string or null.');
        }
        $problems = $data['problems'] ?? null;
        if ($problems !== null && !is_array($problems)) {
            throw new InvalidArgumentException('Problems must be a list.');
        }

        $layoutData = $data;
        $layoutData['sourceUrl'] = $sourceUrl;
        $layoutData['documentTitle'] = $title === null
            ? $this->strings->get('app.name')
            : $this->strings->get('shell.title', ['title' => $title]);
        $layoutData['hasError'] = ($problems !== null && $problems !== []) || ($data['errorTitle'] ?? false) === true;

        $html = $this->render('layout', $scope, $layoutData);

        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /** The appearance for this request (allowlisted classes only). */
    public function appearance(): Appearance
    {
        return $this->appearance;
    }

    /**
     * A UI string as plain, unescaped text (for data passed to templates,
     * which escape it on output).
     *
     * @param array<string, string|int|float> $params
     */
    public function text(string $key, array $params = []): string
    {
        return $this->strings->get($key, $params);
    }

    /** The display formatter (dates, waits, counts, problems). */
    public function format(): Format
    {
        return $this->format;
    }

    /**
     * The CSRF token for the csrf() helper.
     *
     * @internal Used by ViewScope.
     * @throws LogicException when the view has no token
     */
    public function csrfToken(): string
    {
        if ($this->csrfToken === null) {
            throw new LogicException('This view has no CSRF token.');
        }

        return $this->csrfToken;
    }

    /** A configuration value, or null when absent. */
    public function config(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    /**
     * Renders `templates/partials/<name>.php`. Called through ViewScope.
     *
     * @param array<string, mixed> $data
     * @internal
     */
    public function renderPartial(string $name, ViewScope $scope, array $data): string
    {
        return $this->render('partials/' . self::checkName($name), $scope, $data);
    }

    private static function checkName(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid template name.');
        }

        return $name;
    }

    /**
     * Runs one template file inside output buffering. On failure every
     * buffer opened here is discarded before the exception continues, so a
     * half-rendered page is never sent.
     *
     * @param array<string, mixed> $data
     */
    private function render(string $relative, ViewScope $scope, array $data): string
    {
        $file = $this->templateDir . '/' . $relative . '.php';
        if (!is_file($file)) {
            throw new OutOfBoundsException("Template not found: {$relative}");
        }

        $level = ob_get_level();
        ob_start();
        try {
            $scope->run($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        if (ob_get_level() !== $level + 1) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw new LogicException("Template left output buffers unbalanced: {$relative}");
        }

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }
}
