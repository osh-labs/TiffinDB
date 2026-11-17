<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;

/**
 * What the signed-in shell needs about the person and the page (M1 spec
 * part 2 section 4.2). A page whose data key `shell` holds a Shell is
 * rendered in the signed-in shell; null gives the plain shell.
 *
 * Templates cannot call methods on data, so View hands the layout
 * toArray() instead of the object.
 */
final class Shell
{
    /** Areas of the top bar that can be current ('' for none). */
    public const NAVS = ['projects', 'people', 'account', ''];

    private const FLASH_KEY = '/^notice\.[a-z0-9_]+(?:[.-][a-z0-9_]+)*\z/';

    /**
     * @param string                                                 $displayName Plain text; escaped on output.
     * @param string                                                 $initials    Plain text; escaped on output.
     * @param 'projects'|'people'|'account'|''                       $nav
     * @param array{key: string, params: array<string, string|int>}|null $flash  A `notice.*` string key and its parameters.
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $initials,
        public readonly bool $isAdmin,
        public readonly string $nav,
        public readonly ?array $flash,
    ) {
        if (!in_array($nav, self::NAVS, true)) {
            throw new InvalidArgumentException('Unknown top bar area.');
        }
        if ($flash !== null) {
            self::checkFlash($flash);
        }
    }

    /**
     * The same values as an array, for the templates.
     *
     * @return array{displayName: string, initials: string, isAdmin: bool, nav: string, flash: array{key: string, params: array<string, string|int>}|null}
     */
    public function toArray(): array
    {
        return [
            'displayName' => $this->displayName,
            'initials' => $this->initials,
            'isAdmin' => $this->isAdmin,
            'nav' => $this->nav,
            'flash' => $this->flash,
        ];
    }

    /** @param array<array-key, mixed> $flash */
    private static function checkFlash(array $flash): void
    {
        $keys = array_keys($flash);
        sort($keys);
        if ($keys !== ['key', 'params']) {
            throw new InvalidArgumentException('A flash notice is exactly {key, params}.');
        }
        if (!is_string($flash['key']) || preg_match(self::FLASH_KEY, $flash['key']) !== 1) {
            throw new InvalidArgumentException('A flash notice key must be a notice.* string key.');
        }
        if (!is_array($flash['params'])) {
            throw new InvalidArgumentException('Flash notice parameters must be an array.');
        }
        foreach ($flash['params'] as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException('Flash notice parameters map names to strings or integers.');
            }
        }
    }
}
