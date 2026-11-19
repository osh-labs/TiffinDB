<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * Checks shared by the contract values (a private helper of this
 * namespace, which uses nothing but PHP).
 *
 * @internal
 */
final class Values
{
    private function __construct()
    {
    }

    /**
     * Whether $value is valid UTF-8 of $min to $max characters without
     * control characters (tab and line breaks allowed when $multiline).
     */
    public static function isText(string $value, int $min, int $max, bool $multiline = false): bool
    {
        if (preg_match('//u', $value) !== 1) {
            return false;
        }
        $control = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($control, $value) === 1) {
            return false;
        }
        $length = preg_match_all('/./su', $value);

        return $length >= $min && $length <= $max;
    }
}
