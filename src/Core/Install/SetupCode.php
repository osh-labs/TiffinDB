<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Storage\Random;

/**
 * The one-time setup code (P28, M1 spec section 10.5): 16 characters from
 * an alphabet without easily confused letters and digits (about 78 bits),
 * written XXXX-XXXX-XXXX-XXXX. Characters are drawn without bias by
 * rejecting random bytes of 240 or more (240 = 8 × 30).
 */
final class SetupCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
    public const LENGTH = 16;
    public const PATTERN = '/^[ABCDEFGHJKMNPQRSTVWXYZ2-9]{4}(?:-[ABCDEFGHJKMNPQRSTVWXYZ2-9]{4}){3}\z/';
    public const FILE = 'setup-code.txt';

    private function __construct()
    {
    }

    public static function generate(Random $random): string
    {
        $size = strlen(self::ALPHABET);
        $limit = intdiv(256, $size) * $size;
        $characters = '';
        while (strlen($characters) < self::LENGTH) {
            foreach (str_split($random->bytes(self::LENGTH)) as $byte) {
                $value = ord($byte);
                if ($value < $limit && strlen($characters) < self::LENGTH) {
                    $characters .= self::ALPHABET[$value % $size];
                }
            }
        }

        return implode('-', str_split($characters, 4));
    }

    /**
     * Whether $typed is the code in $fileContents: both upper-cased with
     * white space and dashes removed, both non-empty, compared with
     * hash_equals.
     */
    public static function matches(string $fileContents, #[\SensitiveParameter] string $typed): bool
    {
        $expected = self::normalise($fileContents);
        $given = self::normalise($typed);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    private static function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $value));
    }
}
