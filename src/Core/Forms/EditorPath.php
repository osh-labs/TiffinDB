<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * A path naming a block of a form in the editor (M5 spec section 6.1):
 * `i` for the i-th block of the root, `i-j` for the j-th child of the
 * section at root position i, both 1-based.
 */
final class EditorPath
{
    public const PATTERN = '/^[1-9][0-9]{0,3}(?:-[1-9][0-9]{0,3})?\z/';

    public function __construct(public readonly int $root, public readonly ?int $child = null)
    {
        if ($root < 1 || ($child !== null && $child < 1)) {
            throw new \InvalidArgumentException('Path positions start at 1.');
        }
    }

    /** The path written as $value, or null when it is not a path. */
    public static function parse(string $value): ?self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }
        $parts = explode('-', $value);

        return new self((int) $parts[0], isset($parts[1]) ? (int) $parts[1] : null);
    }

    public function value(): string
    {
        return $this->child === null ? (string) $this->root : $this->root . '-' . $this->child;
    }

    /** The block this path names in $document, or null when it names none. */
    public function in(FormDocument $document): ?Block
    {
        $block = $document->blocks[$this->root - 1] ?? null;
        if ($this->child === null || $block === null) {
            return $block;
        }

        return $block instanceof Section ? ($block->children[$this->child - 1] ?? null) : null;
    }
}
