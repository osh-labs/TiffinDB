<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

use TiffinDB\Core\Storage\Random;

/**
 * Cross-site request forgery tokens (ADR 0011 decision 7, M1 spec section
 * 6.8, P33). The web layer decides which expected value applies (the
 * session's token or the double-submit cookie).
 */
final class Csrf
{
    public const LENGTH = 64;
    private const PATTERN = '/^[0-9a-f]{64}\z/';

    private function __construct()
    {
    }

    /** 64 lower-case hex characters from 32 random bytes. */
    public static function generate(Random $random): string
    {
        return bin2hex($random->bytes(32));
    }

    /**
     * True only when $given is a string of 64 characters equal to
     * $expected, compared in constant time. An $expected that is not a
     * well-formed token never matches, so an empty or missing expected
     * value cannot be matched by an empty field.
     */
    public static function matches(#[\SensitiveParameter] string $expected, #[\SensitiveParameter] mixed $given): bool
    {
        if (!is_string($given) || strlen($given) !== self::LENGTH) {
            return false;
        }
        if (preg_match(self::PATTERN, $expected) !== 1) {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
