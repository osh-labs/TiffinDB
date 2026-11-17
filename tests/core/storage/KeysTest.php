<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\SecureRandom;
use TiffinDB\Tests\Support\SeededRandom;

/** T-STO-4: key pattern, determinism from a seed, unique generation. */
#[CoversClass(Keys::class)]
#[CoversClass(SecureRandom::class)]
final class KeysTest extends TestCase
{
    private const SEED = 'tiffindb-keys-test-seed-1';

    public function testConstants(): void
    {
        self::assertSame('/^[a-z2-7]{12}\z/', Keys::PATTERN);
        self::assertSame('abcdefghijklmnopqrstuvwxyz234567', Keys::ALPHABET);
    }

    public function testGeneratedKeysMatchThePattern(): void
    {
        foreach ([new Keys(new SeededRandom(self::SEED)), new Keys(new SecureRandom())] as $keys) {
            for ($i = 0; $i < 200; $i++) {
                $key = $keys->generate();
                self::assertMatchesRegularExpression(Keys::PATTERN, $key);
                self::assertTrue(Keys::isValid($key));
            }
        }
    }

    public function testTheSameSeedGivesTheSameKeys(): void
    {
        $a = new Keys(new SeededRandom(self::SEED));
        $b = new Keys(new SeededRandom(self::SEED));
        $c = new Keys(new SeededRandom('another seed'));

        $first = [$a->generate(), $a->generate(), $a->generate()];
        self::assertSame($first, [$b->generate(), $b->generate(), $b->generate()]);
        self::assertNotSame($first, [$c->generate(), $c->generate(), $c->generate()]);
        self::assertCount(3, array_unique($first));
    }

    public function testKeyUsesTheFirstSixtyBitsOfEightBytes(): void
    {
        $fixed = new class () implements Random {
            /** @var list<int> */
            public array $lengths = [];

            public function bytes(int $length): string
            {
                $this->lengths[] = $length;

                return "\x00\x44\x32\x14\xc7\x42\x54\xbf";
            }
        };
        // 00000 00001 00010 00011 00100 00101 00110 00111 01000 01001 01010 01011 (1111 dropped)
        self::assertSame('abcdefghijkl', (new Keys($fixed))->generate());
        self::assertSame([8], $fixed->lengths);

        $ones = new class () implements Random {
            public function bytes(int $length): string
            {
                return str_repeat("\xff", $length);
            }
        };
        self::assertSame('777777777777', (new Keys($ones))->generate());
        self::assertSame('7777', (new Keys($ones))->short(4));
    }

    public function testGenerateUniqueRetriesOnATakenKey(): void
    {
        $preview = new Keys(new SeededRandom(self::SEED));
        $taken = [$preview->generate(), $preview->generate()];
        $expected = $preview->generate();

        $asked = [];
        $key = (new Keys(new SeededRandom(self::SEED)))->generateUnique(
            function (string $candidate) use ($taken, &$asked): bool {
                $asked[] = $candidate;

                return in_array($candidate, $taken, true);
            },
        );

        self::assertSame($expected, $key);
        self::assertSame([...$taken, $expected], $asked);
    }

    public function testGenerateUniqueFailsAfterTenAttempts(): void
    {
        $attempts = 0;
        try {
            (new Keys(new SeededRandom(self::SEED)))->generateUnique(function () use (&$attempts): bool {
                $attempts++;

                return true;
            });
            self::fail('Ten taken keys must give up.');
        } catch (RuntimeException $e) {
            self::assertSame(10, $attempts);
        }
    }

    public function testShortKeys(): void
    {
        $keys = new Keys(new SeededRandom(self::SEED));
        self::assertMatchesRegularExpression('/^[a-z2-7]{4}\z/', $keys->short(4));
        self::assertMatchesRegularExpression('/^[a-z2-7]{1}\z/', $keys->short(1));
        self::assertMatchesRegularExpression('/^[a-z2-7]{20}\z/', $keys->short(20));
        $this->expectException(\InvalidArgumentException::class);
        $keys->short(0);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function candidates(): iterable
    {
        yield 'valid' => ['q7mzk2wbx4ae', true];
        yield 'all letters' => ['abcdefghijkl', true];
        yield 'all digits' => ['234567234567', true];
        yield 'too short' => ['q7mzk2wbx4a', false];
        yield 'too long' => ['q7mzk2wbx4aea', false];
        yield 'upper case' => ['Q7MZK2WBX4AE', false];
        yield 'digit 1' => ['q7mzk2wbx4a1', false];
        yield 'digit 0' => ['q7mzk2wbx4a0', false];
        yield 'digit 8' => ['q7mzk2wbx4a8', false];
        yield 'trailing newline' => ["q7mzk2wbx4ae\n", false];
        yield 'with prefix' => ['t_q7mzk2wbx4', false];
        yield 'empty' => ['', false];
        yield 'integer' => [123456789012, false];
        yield 'null' => [null, false];
        yield 'array' => [['q7mzk2wbx4ae'], false];
    }

    #[DataProvider('candidates')]
    public function testIsValid(mixed $value, bool $valid): void
    {
        self::assertSame($valid, Keys::isValid($value));
    }

    public function testSeededRandomIsDocumentedConstruction(): void
    {
        $random = new SeededRandom('seed');
        $expected = hash('sha256', 'seed0', true) . hash('sha256', 'seed1', true);
        self::assertSame(substr($expected, 0, 40), $random->bytes(40));
        self::assertSame(substr(hash('sha256', 'seed2', true), 0, 5), $random->bytes(5));
        self::assertSame('', $random->bytes(0));
    }
}
