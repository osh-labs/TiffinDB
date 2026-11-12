<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * The `$this` of every template: the only helpers a template may use, each
 * returning a string that is safe to print (ADR 0008 decision 4).
 *
 * - e(), t(), url(), asset() escape what they return.
 * - csrf() returns the escaped CSRF token, count() the escaped text of
 *   Format::count() (M1 spec part 2 section 4.3).
 * - partial() and slot() return markup produced by templates.
 * - rootClassAttribute() (layout only) returns markup built solely from
 *   Appearance::rootClasses(), which are server-side constants.
 */
final class ViewScope
{
    /** A same-page element reference: `#` and an id as templates write them. */
    private const FRAGMENT = '/^#[A-Za-z][A-Za-z0-9_-]*\z/';

    /** @var array<string, string> */
    private array $slots = [];

    public function __construct(
        private readonly View $view,
        private readonly Strings $strings,
        private readonly UrlGenerator $urls,
        private readonly Appearance $appearance,
    ) {
    }

    /**
     * Escapes a value for an HTML text node or a double-quoted attribute.
     * Invalid UTF-8 and characters not allowed in HTML (C0 controls other
     * than tab, LF, CR, and FF; DEL; C1 controls; noncharacters) become
     * U+FFFD. Form feed, which HTML allows but XML 1.0 does not, becomes
     * U+FFFD too, so the output stays well-formed XML (ADR 0008 decision 4).
     */
    public function e(string|int|float|null $value): string
    {
        $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_HTML5, 'UTF-8');

        return str_replace("\f", "\u{FFFD}", $escaped);
    }

    /**
     * A UI string, escaped, with escaped parameters substituted.
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->strings->format($key, $params, fn (string $text): string => $this->e($text));
    }

    /**
     * An escaped application address, or a reference to an element of the
     * same page (`#id`, used by the error summary to link each problem to
     * its input; the id must be a literal-style id, and no query is allowed).
     *
     * @param array<string, string> $query
     */
    public function url(string $path, array $query = []): string
    {
        if (str_starts_with($path, '#')) {
            if ($query !== [] || preg_match(self::FRAGMENT, $path) !== 1) {
                throw new InvalidArgumentException('Not an element reference.');
            }

            return $this->e($path);
        }

        return $this->e($this->urls->to($path, $query));
    }

    /** The escaped CSRF token, for `<input type="hidden" name="_csrf" value="..." />` only. */
    public function csrf(): string
    {
        return $this->e($this->view->csrfToken());
    }

    /**
     * The escaped text of `<keyBase>.one` or `<keyBase>.many` ({count} is
     * $n with thousands separators), for element text and `aria-label`.
     */
    public function count(string $keyBase, int $n): string
    {
        return $this->e($this->view->format()->count($keyBase, $n));
    }

    /** An escaped static asset address. */
    public function asset(string $file): string
    {
        return $this->e($this->urls->asset($file));
    }

    /**
     * Markup of `templates/partials/<name>.php`, rendered with only $data.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $name, array $data = []): string
    {
        return $this->view->renderPartial($name, $this, $data);
    }

    /** Markup of a named slot (M0 has one: `content`, in the layout). */
    public function slot(string $name): string
    {
        if (!array_key_exists($name, $this->slots)) {
            throw new OutOfBoundsException("Unknown slot: {$name}");
        }

        return $this->slots[$name];
    }

    /**
     * Layout accessor: empty, or ` class="..."` for the root element, built
     * only from the allowlisted appearance classes.
     */
    public function rootClassAttribute(): string
    {
        $classes = $this->appearance->rootClasses();
        if ($classes === []) {
            return '';
        }

        return ' class="' . $this->e(implode(' ', $classes)) . '"';
    }

    /** @internal Used by View. */
    public function setSlot(string $name, string $markup): void
    {
        $this->slots[$name] = $markup;
    }

    /**
     * Runs a template file with `$this` bound to this scope and `$data` as
     * its only variable.
     *
     * @param array<string, mixed> $data
     * @internal Used by View.
     */
    public function run(string $file, array $data): void
    {
        // The file name is passed as an extra argument and read back with
        // func_get_arg(), so that `$data` is the only local variable.
        $runner = function (array $data): void {
            require func_get_arg(1);
        };
        $runner($data, $file);
    }
}
