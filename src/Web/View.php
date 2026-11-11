<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

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
 * Data contract with the templates:
 *
 * - The page template receives the controller's `$data` unchanged.
 * - The layout receives the same `$data` plus `sourceUrl` (the configured
 *   "Source code" link target), which the view sets and a controller cannot
 *   override. By convention `$data['title']` is the page title as plain,
 *   unescaped text, or null for the start page (title "TiffinDB" alone).
 * - A partial receives only the array passed to partial().
 *
 * Template names are fixed strings written in code and must match
 * NAME_PATTERN. They are never derived from request data.
 */
final class View
{
    public const NAME_PATTERN = '#^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)*$#';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly string $templateDir,
        private readonly Strings $strings,
        private readonly UrlGenerator $urls,
        private readonly Appearance $appearance,
        private readonly array $config,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function page(string $template, array $data, int $status = 200): Response
    {
        $scope = new ViewScope($this, $this->strings, $this->urls, $this->appearance);

        $content = $this->render('pages/' . self::checkName($template), $scope, $data);
        $scope->setSlot('content', $content);

        $sourceUrl = $this->config['source_url'] ?? null;
        if (!is_string($sourceUrl)) {
            throw new LogicException('source_url is not configured.');
        }
        $layoutData = $data;
        $layoutData['sourceUrl'] = $sourceUrl;

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
