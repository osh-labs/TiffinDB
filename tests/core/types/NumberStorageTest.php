<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Type\NumberType;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * Review B1: number values with decimals survive a real SQLite NUMERIC
 * column, although SQLite's text-to-real conversion can be one bit off.
 * Ruling P71: the magnitude limit is 10^(15 - decimals), exclusive, for
 * parsing, check(), and the `min` and `max` options.
 */
#[CoversClass(NumberType::class)]
final class NumberStorageTest extends TestCase
{
    private const SEED = 'tiffindb-number-storage-1';
    private const SEEDED_COUNT = 102000;

    /** @return array<string, mixed> */
    private static function options(int $decimals): array
    {
        return ['decimals' => $decimals, 'min' => null, 'max' => null];
    }

    /** The cases the review found, which read back one bit off without snapping. */
    public function testReviewCasesSurviveSqlite(): void
    {
        $type = new NumberType();
        $options = self::options(6);
        $db = self::table();
        $texts = ['27.318581', '7849.515895', '19783.160167'];
        foreach ($texts as $text) {
            $value = $type->parse($text, $options)->value();
            self::assertIsFloat($value);
            $db->run('INSERT INTO "t" ("v") VALUES (?)', [$type->toStorage($value, $options)]);
        }
        $stored = array_column($db->all('SELECT "v" FROM "t" ORDER BY "id"'), 'v');
        foreach ($texts as $i => $text) {
            $back = $type->fromStorage($stored[$i], $options);
            self::assertSame($type->parse($text, $options)->value(), $back, $text);
            self::assertTrue($type->check($back, $options)->isOk(), $text);
            self::assertSame($text, $type->toText($back, $options));
            self::assertSame($back, $type->parse($type->toText($back, $options), $options)->value());
        }
        // The off-by-one-bit value itself, as SQLite has been seen to return it.
        self::assertSame(27.318581, $type->fromStorage(27.318581000000002, $options));
        $db->close();
    }

    public function testValuesFurtherAwayAreKept(): void
    {
        $type = new NumberType();
        self::assertSame(1.2345, $type->fromStorage(1.2345, self::options(2)), 'Stored before decimals was lowered.');
        self::assertSame(0.3, $type->fromStorage(0.30000000000000004, self::options(1)), 'One ulp off snaps.');
        self::assertSame(0.3000001, $type->fromStorage(0.3000001, self::options(6)), 'Seven digits at decimals 6 are kept.');
        self::assertSame(0.0, $type->fromStorage(-0.0, self::options(2)));
        self::assertSame(12.0, $type->fromStorage(12, self::options(2)));
    }

    /** At least 100,000 seeded values at decimals 1 to 6 through a real column. */
    public function testSeededValuesSurviveSqlite(): void
    {
        $type = new NumberType();
        $random = new SeededRandom(self::SEED);
        $db = self::table();
        $cases = [];
        $db->write(function (Database $db) use ($type, $random, &$cases): void {
            for ($i = 0; $i < self::SEEDED_COUNT; $i++) {
                $decimals = 1 + $i % 6;
                $raw = unpack('J2', $random->bytes(16));
                $digits = 15 - $decimals;
                // Spread magnitudes over every integer-part length allowed.
                $length = 1 + (($raw[1] & PHP_INT_MAX) % $digits);
                $integer = (string) (($raw[2] & PHP_INT_MAX) % (10 ** $length));
                $fraction = str_pad((string) (($raw[1] >> 8 & PHP_INT_MAX) % (10 ** $decimals)), $decimals, '0', STR_PAD_LEFT);
                $text = $integer . '.' . $fraction;
                if (($raw[2] & 1) === 1 && trim($text, '0.') !== '') {
                    $text = '-' . $text;
                }
                $options = self::options($decimals);
                $value = $type->parse($text, $options)->value();
                $db->run('INSERT INTO "t" ("v") VALUES (?)', [$type->toStorage($value, $options)]);
                $cases[] = [$decimals, $text, $value];
            }
        });
        $stored = array_column($db->all('SELECT "v" FROM "t" ORDER BY "id"'), 'v');
        self::assertCount(self::SEEDED_COUNT, $stored);
        $failures = [];
        foreach ($cases as $i => [$decimals, $text, $value]) {
            $options = self::options($decimals);
            $back = $type->fromStorage($stored[$i], $options);
            if ($back !== $value || !$type->check($back, $options)->isOk() || $type->toText($back, $options) !== $text) {
                $failures[] = $text . ' (decimals ' . $decimals . ') read back as ' . var_export($stored[$i], true);
            }
        }
        self::assertSame([], array_slice($failures, 0, 10), count($failures) . ' values did not survive.');
        $db->close();
    }

    /** @return iterable<string, array{int}> */
    public static function decimals(): iterable
    {
        for ($decimals = 0; $decimals <= 6; $decimals++) {
            yield 'decimals ' . $decimals => [$decimals];
        }
    }

    /** P71: the largest accepted magnitude and the smallest refused one, for each value of decimals. */
    #[DataProvider('decimals')]
    public function testMagnitudeBoundary(int $decimals): void
    {
        $type = new NumberType();
        $options = self::options($decimals);
        $limit = NumberType::limit($decimals);
        self::assertSame(10 ** (15 - $decimals), $limit);
        $largest = str_repeat('9', 15 - $decimals) . ($decimals > 0 ? '.' . str_repeat('9', $decimals) : '');
        $limitText = $limit . ($decimals > 0 ? '.' . str_repeat('0', $decimals) : '');

        foreach (['', '-'] as $sign) {
            $accepted = $type->parse($sign . $largest, $options);
            self::assertTrue($accepted->isOk(), $sign . $largest);
            self::assertSame($sign . $largest, $type->toText($accepted->value(), $options));
            self::assertTrue($type->check($accepted->value(), $options)->isOk());
            self::assertSame('problem.number.range', $type->parse($sign . $limitText, $options)->problemKey(), $sign . $limitText);
            self::assertSame('problem.number.range', $type->parse($sign . $limit, $options, lenient: true)->problemKey());
        }
        if ($decimals < 6) {
            self::assertSame('problem.number.range', $type->parse($largest . '9', $options, lenient: true)->problemKey(), 'Rounding up to the limit is refused.');
        }
        $limitValue = $decimals === 0 ? $limit : (float) $limit;
        self::assertSame('problem.number.range', $type->check($limitValue, $options)->problemKey());
        self::assertSame('problem.number.range', $type->check(-$limitValue, $options)->problemKey());
        self::assertSame('problem.number.range', $type->check($limit, $options)->problemKey(), 'An int at the limit.');
        self::assertTrue($type->check($limit - 1, $options)->isOk());

        $belowLimit = $type->parse($largest, $options)->value();
        $normalised = $type->normaliseOptions(['decimals' => $decimals, 'min' => '-' . $largest, 'max' => $largest]);
        self::assertSame(-$belowLimit, $normalised['min']);
        self::assertSame($belowLimit, $normalised['max']);
        foreach (['min', 'max'] as $name) {
            foreach ([$limit, -$limit, (float) $limit, (string) $limit] as $bound) {
                try {
                    $type->normaliseOptions(['decimals' => $decimals, $name => $bound]);
                    self::fail($name . ' ' . var_export($bound, true) . ' was accepted.');
                } catch (ValidationFailed $e) {
                    self::assertSame('options.' . $name, $e->problems[0]->field);
                    self::assertSame('problem.number.invalid', $e->problems[0]->key);
                }
            }
        }
    }

    private static function table(): Database
    {
        $db = Sqlite::memory();
        $db->run('CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "v" ' . (new NumberType())->columnDefinition() . ')');

        return $db;
    }
}
