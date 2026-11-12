<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/**
 * Password hashing with password_hash (ADR 0011 decision 4, M1 spec
 * section 6.2).
 *
 * Default: Argon2id (memory_cost 19456 KiB, time_cost 2, threads 1) where
 * PHP provides it, else bcrypt cost 12. Options for tests and later tuning:
 * `['bcrypt' => <cost>]` or `['argon2id' => ['memory_cost' => ..,
 * 'time_cost' => .., 'threads' => ..]]`.
 *
 * verifyDummy() runs one verification against a fixed dummy hash, so that
 * signing in with an unknown username costs what a known one does. With
 * the default parameters the dummy is one of the constants below (hashes
 * of a fixed throwaway string, made once with exactly those parameters);
 * only with other options is a dummy made, in the constructor, never on
 * first use. Passwords are never logged or put in exception messages.
 */
final class PasswordHasher
{
    public const ALGORITHM_ARGON2ID = 'argon2id';
    public const ALGORITHM_BCRYPT = 'bcrypt';
    public const DEFAULT_ARGON2ID = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];
    public const DEFAULT_BCRYPT_COST = 12;

    /** password_hash of a throwaway string with DEFAULT_ARGON2ID. Not a credential. */
    public const DUMMY_ARGON2ID = '$argon2id$v=19$m=19456,t=2,p=1$YWdOdXdHZzM4Z3R6N2JTag$JqKaUInG5hWaExhuxbgGQzing+WWNshKUpvKJ1jG//U';
    /** password_hash of a throwaway string with bcrypt cost 12. Not a credential. */
    public const DUMMY_BCRYPT = '$2y$12$SG973o0nMEsamsupukd0DuR4jTRM6Az8J7YsU6eq.0uks.OyAxwNO';

    private readonly string $algorithm;
    /** @var array<string, int> */
    private readonly array $options;
    private readonly string $dummy;
    private int $hashCalls = 0;
    private int $verifyCalls = 0;

    /** @param array<string, mixed>|null $options */
    public function __construct(?array $options = null)
    {
        [$this->algorithm, $this->options] = self::resolve($options);
        if ($this->algorithm === self::ALGORITHM_ARGON2ID && $this->options === self::DEFAULT_ARGON2ID) {
            $this->dummy = self::DUMMY_ARGON2ID;
        } elseif ($this->algorithm === self::ALGORITHM_BCRYPT && $this->options === ['cost' => self::DEFAULT_BCRYPT_COST]) {
            $this->dummy = self::DUMMY_BCRYPT;
        } else {
            $this->dummy = $this->hash('tiffindb dummy password');
        }
    }

    /** Whether PHP provides Argon2id. */
    public static function argon2idAvailable(): bool
    {
        return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        if (str_contains($password, "\0")) {
            throw new \InvalidArgumentException('A password must not contain a NUL byte.');
        }
        $this->hashCalls++;

        return password_hash($password, $this->phpAlgorithm(), $this->options);
    }

    /**
     * Constant-time comparison is done by password_verify. A password with
     * a NUL byte never verifies (bcrypt would compare only the part before
     * it); the verification still runs, so the cost is the same.
     */
    public function verify(#[\SensitiveParameter] string $password, string $hash): bool
    {
        $this->verifyCalls++;
        $ok = password_verify($password, $hash);

        return $ok && !str_contains($password, "\0");
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->phpAlgorithm(), $this->options);
    }

    /** 'argon2id' or 'bcrypt'. */
    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /** One verification against the dummy hash; the result is discarded and nothing is hashed. */
    public function verifyDummy(#[\SensitiveParameter] string $password): void
    {
        $this->verifyCalls++;
        password_verify($password, $this->dummy);
    }

    /** Test seam (T-AUTH-2): how many hashes this instance has made, the constructor's dummy included. */
    public function hashCalls(): int
    {
        return $this->hashCalls;
    }

    /** Test seam (T-AUTH-7): how many verifications this instance has run, verifyDummy() included. */
    public function verifyCalls(): int
    {
        return $this->verifyCalls;
    }

    private function phpAlgorithm(): string
    {
        return $this->algorithm === self::ALGORITHM_ARGON2ID ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * @param array<string, mixed>|null $options
     * @return array{0: string, 1: array<string, int>}
     */
    private static function resolve(?array $options): array
    {
        if ($options === null) {
            return self::argon2idAvailable()
                ? [self::ALGORITHM_ARGON2ID, self::DEFAULT_ARGON2ID]
                : [self::ALGORITHM_BCRYPT, ['cost' => self::DEFAULT_BCRYPT_COST]];
        }
        if (array_keys($options) === [self::ALGORITHM_BCRYPT]) {
            $cost = $options[self::ALGORITHM_BCRYPT];
            if (!is_int($cost) || $cost < 4 || $cost > 31) {
                throw new \InvalidArgumentException('The bcrypt cost must be an integer from 4 to 31.');
            }

            return [self::ALGORITHM_BCRYPT, ['cost' => $cost]];
        }
        if (array_keys($options) === [self::ALGORITHM_ARGON2ID]) {
            if (!self::argon2idAvailable()) {
                throw new \InvalidArgumentException('Argon2id is not available in this PHP.');
            }
            $given = $options[self::ALGORITHM_ARGON2ID];
            if (!is_array($given)) {
                throw new \InvalidArgumentException('Argon2id options must be an array.');
            }
            $resolved = [];
            foreach (array_keys(self::DEFAULT_ARGON2ID) as $name) {
                $value = $given[$name] ?? null;
                if (!is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException('Argon2id options need positive integers for memory_cost, time_cost, and threads.');
                }
                $resolved[$name] = $value;
            }
            if (count($given) !== count($resolved)) {
                throw new \InvalidArgumentException('Unknown Argon2id option.');
            }

            return [self::ALGORITHM_ARGON2ID, $resolved];
        }

        throw new \InvalidArgumentException('Unknown password hasher options.');
    }
}
