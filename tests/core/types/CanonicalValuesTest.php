<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-TYP-3: parse(toText(v)) gives v back, for 200 canonical values per type
 * that has values, generated from a seed written below.
 * T-TYP-4: toStorage, insert into SQLite (the column definition of the
 * type), read back, fromStorage gives the same canonical value.
 *
 * Auto-number values are typed by nobody, so its round trip uses lenient
 * parsing (import), the only mode that accepts numbers. Attachment and
 * relation values live outside the user table and are not parseable.
 */
#[CoversNamespace('TiffinDB\\Core\\Schema\\Type')]
final class CanonicalValuesTest extends TestCase
{
    private const SEED = 'tiffindb-types-canonical-values-1';
    private const COUNT = 200;
    private const TEXT_CHARS = ['a', 'Z', '0', 'é', '中', '😀', '-', '"', '<', '&', "'", 'ß', "\u{200D}"];
    private const INNER_CHARS = [' ', "\t", ' '];

    private SeededRandom $random;

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function typesWithValues(): iterable
    {
        yield 'text' => ['text', ['max_length' => 500]];
        yield 'long_text' => ['long_text', ['max_length' => 20000]];
        yield 'number, 0 decimals' => ['number', ['decimals' => 0, 'min' => null, 'max' => null]];
        yield 'number, 2 decimals' => ['number', ['decimals' => 2, 'min' => null, 'max' => null]];
        yield 'number, 6 decimals' => ['number', ['decimals' => 6, 'min' => null, 'max' => null]];
        yield 'currency, 2 digits' => ['currency', ['code' => 'USD', 'minor_digits' => 2]];
        yield 'currency, 0 digits' => ['currency', ['code' => 'JPY', 'minor_digits' => 0]];
        yield 'currency, 4 digits' => ['currency', ['code' => 'XAU', 'minor_digits' => 4]];
        yield 'date' => ['date', []];
        yield 'time' => ['time', ['seconds' => true]];
        yield 'date_time' => ['date_time', ['seconds' => false]];
        yield 'duration' => ['duration', ['seconds' => false]];
        yield 'checkbox' => ['checkbox', []];
        yield 'choice' => ['choice', []];
        yield 'rating' => ['rating', ['max' => 10]];
        yield 'url' => ['url', []];
        yield 'email' => ['email', []];
        yield 'phone' => ['phone', []];
        yield 'auto_number' => ['auto_number', ['start' => 1, 'next' => 1]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('typesWithValues')]
    public function testTextRoundTrip(string $type, array $options): void
    {
        $types = self::types();
        $fieldType = $types->get($type);
        [$options, $values] = $this->values($type, $options, $types);
        self::assertCount(self::COUNT, $values);
        foreach ($values as $value) {
            $checked = $fieldType->check($value, $options);
            self::assertTrue($checked->isOk(), var_export($value, true) . ' is canonical: ' . $checked->problemKey());
            self::assertSame($value, $checked->value());
            $text = $fieldType->toText($value, $options);
            $back = $fieldType->parse($text, $options, lenient: $type === 'auto_number');
            self::assertTrue($back->isOk(), var_export($text, true) . ' parses: ' . $back->problemKey());
            self::assertSame($value, $back->value(), var_export($text, true));
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('typesWithValues')]
    public function testStorageRoundTrip(string $type, array $options): void
    {
        $types = self::types();
        $fieldType = $types->get($type);
        [$options, $values] = $this->values($type, $options, $types);
        $values[] = $type === 'checkbox' ? false : null;
        $db = Sqlite::memory();
        $db->run('CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "v" ' . $fieldType->columnDefinition() . ')');
        foreach ($values as $value) {
            $db->run('INSERT INTO "t" ("v") VALUES (?)', [$fieldType->toStorage($value, $options)]);
        }
        $rows = $db->all('SELECT "v", typeof("v") AS "kind" FROM "t" ORDER BY "id"');
        self::assertCount(count($values), $rows);
        foreach ($rows as $i => $row) {
            self::assertSame($values[$i], $fieldType->fromStorage($row['v'], $options), 'Row ' . $i);
            self::assertContains($row['kind'], self::storageKinds($type, $values[$i]), 'Row ' . $i);
        }
        $db->close();
    }

    public function testCheckboxNullIsStoredAsZero(): void
    {
        $checkbox = self::types()->get('checkbox');
        $db = Sqlite::memory();
        $db->run('CREATE TABLE "t" ("id" INTEGER PRIMARY KEY, "v" ' . $checkbox->columnDefinition() . ')');
        $db->run('INSERT INTO "t" ("v") VALUES (?)', [$checkbox->toStorage(null, [])]);
        $db->run('INSERT INTO "t" ("id") VALUES (2)');
        self::assertSame([0, 0], array_column($db->all('SELECT "v" FROM "t" ORDER BY "id"'), 'v'));
        self::assertFalse($checkbox->fromStorage(0, []));
        self::assertTrue($checkbox->fromStorage(1, []));
        $db->close();
    }

    public function testTheSameSeedGivesTheSameValues(): void
    {
        $types = self::types();
        foreach (['text', 'number', 'url', 'email', 'phone'] as $type) {
            $this->random = new SeededRandom(self::SEED);
            $first = $this->generate($type, ['decimals' => 2], 5);
            $this->random = new SeededRandom(self::SEED);
            self::assertSame($first, $this->generate($type, ['decimals' => 2], 5), $type);
        }
        self::assertSame(['text', 'long_text'], array_slice($types->ids(), 0, 2));
    }

    /** @return list<string> SQLite storage classes a stored value may have */
    private static function storageKinds(string $type, mixed $value): array
    {
        if ($value === null) {
            return ['null'];
        }

        return match ($type) {
            'number' => is_float($value) && floor($value) !== $value ? ['real'] : ['integer'],
            'currency', 'duration', 'checkbox', 'rating', 'auto_number' => ['integer'],
            default => ['text'],
        };
    }

    /**
     * Normalised options and 200 canonical values.
     *
     * @param array<string, mixed> $options
     * @return array{0: array<string, mixed>, 1: list<mixed>}
     */
    private function values(string $type, array $options, FieldTypes $types): array
    {
        $this->random = new SeededRandom(self::SEED . '/' . $type . '/' . json_encode($options));
        if ($type === 'choice') {
            $labels = [];
            for ($i = 0; $i < 30; $i++) {
                $labels[] = 'Choice ' . $i . ' ' . $this->label(1, 8) . ' Ünï';
            }
            $options = ['choices' => $labels];
        }
        $options = $types->get($type)->normaliseOptions($options);

        return [$options, $this->generate($type, $options, self::COUNT)];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<mixed>
     */
    private function generate(string $type, array $options, int $count): array
    {
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = match ($type) {
                'text' => $this->text(1, 60, false),
                'long_text' => $this->text(1, 200, true),
                'number' => $this->number((int) ($options['decimals'] ?? 0)),
                'currency' => $this->int(-9000000000000000, 9000000000000000),
                'date' => $this->date(),
                'time' => $this->time(),
                'date_time' => $this->date() . 'T' . $this->time(),
                'duration' => $this->int(0, 3153600000),
                'checkbox' => $this->int(0, 1) === 1,
                'choice' => $options['choices'][$this->int(0, count($options['choices']) - 1)]['label'],
                'rating' => $this->int(1, 10),
                'url' => $this->url(),
                'email' => $this->email(),
                'phone' => $this->phone(),
                'auto_number' => $this->int(0, $i % 2 === 0 ? 1000 : 9007199254740991),
            };
        }

        return $values;
    }

    private function int(int $min, int $max): int
    {
        $raw = unpack('J', $this->random->bytes(8));
        $span = $max - $min + 1;

        return $min + (($raw[1] & PHP_INT_MAX) % $span);
    }

    private function pick(string $chars): string
    {
        return $chars[$this->int(0, strlen($chars) - 1)];
    }

    private function text(int $min, int $max, bool $lines): string
    {
        $length = $this->int($min, $max);
        $inner = $lines ? [...self::INNER_CHARS, "\n", "\n"] : self::INNER_CHARS;
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $useInner = $i > 0 && $i < $length - 1 && $this->int(0, 4) === 0;
            $text .= $useInner
                ? $inner[$this->int(0, count($inner) - 1)]
                : self::TEXT_CHARS[$this->int(0, count(self::TEXT_CHARS) - 1)];
        }

        return $text;
    }

    private function number(int $decimals): int|float
    {
        if ($decimals === 0) {
            return $this->int(-999999999999999, 999999999999999);
        }
        // Below the P71 limit 10^(15 - decimals).
        $integer = $this->int(0, 3) === 0 ? $this->int(0, 10 ** (15 - $decimals) - 1) : $this->int(0, 99999);
        $fraction = str_pad((string) $this->int(0, 10 ** $decimals - 1), $decimals, '0', STR_PAD_LEFT);
        $value = (float) ($integer . '.' . $fraction);
        $value = $this->int(0, 1) === 1 && $value != 0.0 ? -$value : $value;

        return (float) sprintf('%.' . $decimals . 'F', $value);
    }

    private function date(): string
    {
        do {
            $year = $this->int(1, 9999);
            $month = $this->int(1, 12);
            $day = $this->int(1, 31);
        } while (!checkdate($month, $day, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function time(): string
    {
        return sprintf('%02d:%02d:%02d', $this->int(0, 23), $this->int(0, 59), $this->int(0, 59));
    }

    private function label(int $min, int $max): string
    {
        $label = $this->pick('abcdefghijklmnopqrstuvwxyz0123456789');
        $length = $this->int($min, $max);
        for ($i = 1; $i < $length; $i++) {
            $label .= $this->pick('abcdefghijklmnopqrstuvwxyz0123456789-');
        }

        return rtrim($label, '-') . $this->pick('abcdefghijklmnopqrstuvwxyz');
    }

    private function url(): string
    {
        $url = ($this->int(0, 1) === 1 ? 'https' : 'http') . '://' . $this->label(1, 10) . '.example';
        if ($this->int(0, 3) === 0) {
            $url .= ':' . $this->int(1, 65535);
        }
        $segments = $this->int(0, 4);
        for ($i = 0; $i < $segments; $i++) {
            $url .= '/' . $this->label(1, 12);
        }
        if ($this->int(0, 2) === 0) {
            $url .= '?q=' . $this->label(1, 6) . '&n=' . $this->int(0, 999);
        }
        if ($this->int(0, 4) === 0) {
            $url .= '#' . $this->label(1, 6);
        }

        return $url;
    }

    private function email(): string
    {
        $local = $this->label(1, 10);
        if ($this->int(0, 2) === 0) {
            $local .= $this->pick('.+_') . $this->label(1, 6);
        }

        // RFC 2606 names only: a subdomain of example.com, example.org, or example.net.
        return $local . '@' . $this->label(1, 10) . '.example.' . ['com', 'org', 'net'][$this->int(0, 2)];
    }

    private function phone(): string
    {
        $phone = $this->int(0, 1) === 1 ? '+1 (555) ' : '555-';
        $phone .= sprintf('01%02d', $this->int(0, 99));
        $extensions = ['', ' x', ' ext ', ' ext.'];
        $extension = $extensions[$this->int(0, 3)];

        return $extension === '' ? $phone : $phone . $extension . $this->int(1, 9999);
    }

    private static function types(): FieldTypes
    {
        return FieldTypes::standard(new Keys(new SeededRandom(self::SEED . '/keys')));
    }
}
