<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * Keys (ADR 0010 decision 1): 12 characters from the lower-case RFC 4648
 * base32 alphabet, made from 60 random bits.
 */
final class Keys
{
    public const PATTERN = '/^[a-z2-7]{12}\z/';
    public const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';
    public const LENGTH = 12;
    public const MAX_ATTEMPTS = 10;

    public function __construct(private readonly Random $random)
    {
    }

    /** A new key: 8 random bytes, of which the first 60 bits give 12 characters of 5 bits each. */
    public function generate(): string
    {
        return self::encode($this->random->bytes(8), self::LENGTH);
    }

    /**
     * A key for which $taken returns false. Tries up to 10 keys, then
     * throws \RuntimeException.
     *
     * @param callable(string): bool $taken
     */
    public function generateUnique(callable $taken): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $key = $this->generate();
            if (!$taken($key)) {
                return $key;
            }
        }

        throw new \RuntimeException('No free key after ' . self::MAX_ATTEMPTS . ' attempts.');
    }

    /** A short random string from the key alphabet, for example the 4-character snapshot suffix. */
    public function short(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('The length must be 1 or more.');
        }

        return self::encode($this->random->bytes(intdiv($length * 5 + 7, 8)), $length);
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /** The first $length * 5 bits of $bytes, most significant first, as alphabet characters. */
    private static function encode(string $bytes, int $length): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        if (strlen($bits) < $length * 5) {
            throw new \LogicException('The random source returned too few bytes.');
        }
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= self::ALPHABET[bindec(substr($bits, $i * 5, 5))];
        }

        return $key;
    }
}
