<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Storage\Keys;

/**
 * A form editor action read from the posted `do` value (M5 spec section
 * 6.3): `apply`, `save`, `add-section`, `add-<field key>`, or
 * `<verb>-<path>` with verb `up`, `down`, `hide`, `remove`, or `ungroup`.
 */
final class EditorAction
{
    public const APPLY = 'apply';
    public const SAVE = 'save';
    public const ADD_SECTION = 'add-section';
    public const ADD = 'add';
    public const UP = 'up';
    public const DOWN = 'down';
    public const HIDE = 'hide';
    public const REMOVE = 'remove';
    public const UNGROUP = 'ungroup';

    private const PATH_VERBS = [self::UP, self::DOWN, self::HIDE, self::REMOVE, self::UNGROUP];

    private function __construct(
        public readonly string $verb,
        public readonly ?EditorPath $path = null,
        public readonly ?string $fieldKey = null,
    ) {
    }

    /** The action $value names, or null for anything else (treated as apply). */
    public static function parse(string $value): ?self
    {
        if ($value === self::APPLY || $value === self::SAVE || $value === self::ADD_SECTION) {
            return new self($value);
        }
        $dash = strpos($value, '-');
        if ($dash === false) {
            return null;
        }
        $verb = substr($value, 0, $dash);
        $rest = substr($value, $dash + 1);
        if ($verb === self::ADD) {
            return Keys::isValid($rest) ? new self(self::ADD, null, $rest) : null;
        }
        if (!in_array($verb, self::PATH_VERBS, true)) {
            return null;
        }
        $path = EditorPath::parse($rest);

        return $path === null ? null : new self($verb, $path);
    }

    public static function apply(): self
    {
        return new self(self::APPLY);
    }

    public static function save(): self
    {
        return new self(self::SAVE);
    }

    public static function addSection(): self
    {
        return new self(self::ADD_SECTION);
    }

    public static function add(string $fieldKey): self
    {
        if (!Keys::isValid($fieldKey)) {
            throw new \InvalidArgumentException('The field key is not valid.');
        }

        return new self(self::ADD, null, $fieldKey);
    }

    public static function on(string $verb, EditorPath $path): self
    {
        if (!in_array($verb, self::PATH_VERBS, true)) {
            throw new \InvalidArgumentException('The verb does not take a path.');
        }

        return new self($verb, $path);
    }

    /** The posted value that names this action. */
    public function value(): string
    {
        return match (true) {
            $this->path !== null => $this->verb . '-' . $this->path->value(),
            $this->fieldKey !== null => self::ADD . '-' . $this->fieldKey,
            default => $this->verb,
        };
    }
}
