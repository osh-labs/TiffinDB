<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Text;

/**
 * Private helper for `attachment` and `relation`, whose canonical value is
 * a list of ids kept outside the user table (M1 spec section 8.2; links in
 * M2 and M4). People never type it, so parsing non-empty text gives
 * problem.value.unsupported.
 */
final class IdList
{
    private function __construct()
    {
    }

    /**
     * Empty text gives null, as for every type (section 8.1); anything else
     * gives problem.value.unsupported in both modes.
     */
    public static function parse(string $text): Checked
    {
        return Text::isUtf8($text) && Text::trim($text) === ''
            ? Checked::ok(null)
            : Checked::problem('problem.value.unsupported');
    }

    /** Null, or a list of distinct positive ints. */
    public static function check(mixed $value): Checked
    {
        if ($value === null) {
            return Checked::ok(null);
        }
        if (!is_array($value) || !array_is_list($value)) {
            return Checked::problem('problem.value.unsupported');
        }
        $seen = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                return Checked::problem('problem.value.unsupported');
            }
            $seen[$id] = true;
        }

        return Checked::ok($value);
    }

    /** Ids joined by `,`; '' for null. */
    public static function toText(mixed $value): string
    {
        $checked = self::check($value);
        if (!$checked->isOk()) {
            throw new \InvalidArgumentException('A canonical id list is a list of distinct positive ints, or null.');
        }

        return $value === null ? '' : implode(',', $value);
    }
}
