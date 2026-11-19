<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

/**
 * Seeded synthetic import data (M3 spec section 15; first, seed-only
 * version for M3-WP1: M3-WP11 extends it with the full "Home Inventory"
 * project). Every value is generated from SEED with a small linear
 * congruential generator, so the same call always gives the same rows.
 * Names are made-up item words, never real people or places.
 */
final class IoFixture
{
    public const SEED = 20261119;
    public const HEADINGS = ['Name', 'Room', 'Price', 'Purchased', 'Quantity', 'In use', 'Notes'];
    public const ROOMS = ['Kitchen', 'Garage', 'Office', 'Hall'];
    private const NOUNS = ['Lamp', 'Kettle', 'Shelf', 'Rug', 'Toaster', 'Drill', 'Ladder', 'Clock', 'Fan', 'Desk', 'Mirror', 'Stool'];
    private const ADJECTIVES = ['Blue', 'Small', 'Old', 'Spare', 'Folding', 'Brass', 'Long', 'Round'];

    private int $state;

    public function __construct(int $seed = self::SEED)
    {
        $this->state = $seed & 0x7FFFFFFF;
    }

    /** The next number from 0 to $max - 1. */
    public function next(int $max): int
    {
        $this->state = (1103515245 * $this->state + 12345) & 0x7FFFFFFF;

        return intdiv($this->state, 65536) % $max;
    }

    /**
     * Item rows as text cells in HEADINGS order: a unique name, a room, a
     * price with a dollar sign, an ISO date, a small count, yes or no, and
     * an optional note.
     *
     * @return list<list<string>>
     */
    public function itemRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $name = self::ADJECTIVES[$this->next(count(self::ADJECTIVES))] . ' ' . self::NOUNS[$this->next(count(self::NOUNS))] . ' ' . $i;
            $price = '$' . ($this->next(500) + 1) . '.' . str_pad((string) $this->next(100), 2, '0', STR_PAD_LEFT);
            $date = sprintf('2025-%02d-%02d', $this->next(12) + 1, $this->next(28) + 1);
            $rows[] = [
                $name,
                self::ROOMS[$this->next(count(self::ROOMS))],
                $price,
                $date,
                (string) ($this->next(9) + 1),
                $this->next(2) === 0 ? 'yes' : 'no',
                $this->next(3) === 0 ? 'Synthetic note ' . $i : '',
            ];
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    public static function csv(array $headings, array $rows): string
    {
        $lines = [];
        foreach ([$headings, ...$rows] as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = preg_match('/[",\r\n]/', $cell) === 1 ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\r\n", $lines) . "\r\n";
    }
}
