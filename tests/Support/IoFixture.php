<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Support;

use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Database;

/**
 * Seeded synthetic import and export data (M3 spec section 15.7). Every
 * value is generated from SEED (20261119) with a small linear congruential
 * generator (`state = (1103515245 * state + 12345) mod 2^31`; a draw below
 * `max` is `floor(state / 65536) mod max`), so the same call always gives
 * the same rows. Names are made-up item words, never real people or
 * places; web and email addresses use example.com, phone numbers the
 * 555-01xx range.
 *
 * What it makes:
 *
 * - itemRows() and csv(): the seed-only first version (M3-WP1), unchanged.
 * - canonicalRows() and canonicalCsv(): the T-RT-1 file. Each cell is
 *   already in the form the CSV export writes for the type the import
 *   proposes for its column (CANONICAL_TYPES), with a byte order mark,
 *   CRLF line ends, and quoting only where the writer quotes, so that an
 *   import with the default mapping followed by a CSV export gives the
 *   same bytes. Phone numbers are read as text (no rule proposes phone);
 *   the In use column is yes or no, which the import proposes as a
 *   checkbox and the CSV export writes back as yes or no (P145).
 * - sampleRows() and sampleCsv(): `inventory-sample.csv` of the import
 *   review mock-up (docs/design/model-ui/import.html, visual check V-15):
 *   52 rows and the eight columns shown, "Stand mixer" and "Cordless
 *   drill" first, three purchase dates that cannot be read, and two room
 *   names that are not in the M2 fixture's Rooms.
 * - project(): the M2 "Home Inventory" data set (M2Fixture) plus a table
 *   Samples with the remaining types (number, currency in euros, time,
 *   date and time, duration, email, phone, auto-number, attachment), a
 *   link to Rooms, a link from Samples to itself, a record in the trash,
 *   and attached files that really exist in the project folder (the M2
 *   attachment rows have no files), so a project archive carries files.
 * - largeProject(): one table of $count parts linked to each other
 *   through a self-relation, inserted with plain SQL (T-RT-6 timings).
 */
final class IoFixture
{
    public const SEED = 20261119;
    public const HEADINGS = ['Name', 'Room', 'Price', 'Purchased', 'Quantity', 'In use', 'Notes'];
    public const ROOMS = ['Kitchen', 'Garage', 'Office', 'Hall'];

    /** T-RT-1 headings, in the form the CSV export writes field names. */
    public const CANONICAL_HEADINGS = [
        'Name', 'Room', 'Category', 'Purchased', 'Value', 'Weight', 'Quantity', 'Delivered', 'Opens at', 'Run time',
        'Manual', 'Contact', 'Phone', 'Notes', 'In use',
    ];

    /** The type the import proposes for each T-RT-1 column ('link' for the Room column). */
    public const CANONICAL_TYPES = [
        'text', 'link', 'choice', 'date', 'currency', 'number', 'number', 'date_time', 'time', 'duration',
        'url', 'email', 'text', 'long_text', 'checkbox',
    ];

    /** The mock-up's headings (import.html), in its order. */
    public const SAMPLE_HEADINGS = ['name', 'room', 'category', 'purchased', 'value', 'condition', 'notes', 'row_id'];

    /** Room names of sampleRows() that the M2 fixture's Rooms does not hold. */
    public const SAMPLE_NEW_ROOMS = ['Attic', 'Shed'];

    /** Rows of sampleRows() whose purchase date cannot be read (1-based data row numbers). */
    public const SAMPLE_BAD_DATES = [17, 29, 44];

    public const SAMPLE_ROWS = 52;

    private const NOUNS = ['Lamp', 'Kettle', 'Shelf', 'Rug', 'Toaster', 'Drill', 'Ladder', 'Clock', 'Fan', 'Desk', 'Mirror', 'Stool'];
    private const ADJECTIVES = ['Blue', 'Small', 'Old', 'Spare', 'Folding', 'Brass', 'Long', 'Round'];
    private const CATEGORIES = ['Appliance', 'Cookware', 'Electronics', 'Furniture', 'Outdoor', 'Tool'];
    private const SAMPLE_FIRST = [
        ['Stand mixer', 'Kitchen', 'Appliance', '2025-03-14', '329.00', '5', "Comes with dough hook, whisk, and flat beater.\nSpare bowl is on the top shelf."],
        ['Cordless drill', 'Garage', 'Tool', '2023-11-02', '149.00', '4', ''],
    ];
    private const BAD_DATE_TEXT = ['last spring', 'unknown', 'soon after moving'];

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

    /**
     * T-RT-1 rows in CANONICAL_HEADINGS order ($count of at least 40).
     * $rooms are the titles the Room column names (they should exist in
     * the target project). Optional columns are empty in some rows; every
     * column has values, and only Category repeats few enough values to be
     * read as a choice.
     *
     * @param list<string> $rooms
     * @return list<list<string>>
     */
    public function canonicalRows(int $count, array $rooms): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $month = $this->next(12) + 1;
            $day = $this->next(28) + 1;
            $notes = $i % 4 === 0 ? '' : 'Synthetic note ' . $i . ($i % 3 === 0 ? ".\nSecond line, with \"quotes\"" : '');
            $rows[] = [
                self::ADJECTIVES[$this->next(count(self::ADJECTIVES))] . ' ' . self::NOUNS[$this->next(count(self::NOUNS))] . ' ' . $i,
                $i % 7 === 0 ? '' : $rooms[$this->next(count($rooms))],
                self::CATEGORIES[$this->next(count(self::CATEGORIES))],
                sprintf('20%02d-%02d-%02d', 18 + $this->next(8), $month, $day),
                ($this->next(900) + 10) . '.' . str_pad((string) ($this->next(4) * 25), 2, '0', STR_PAD_LEFT),
                ($this->next(40) + 1) . '.' . $this->next(10) . ($this->next(9) + 1),
                (string) ($this->next(60) + 1),
                sprintf('2025-%02d-%02dT%02d:%02d:%02d', $month, $day, $this->next(24), $this->next(60), $this->next(60)),
                sprintf('%02d:%02d:00', $this->next(24), $this->next(4) * 15),
                sprintf('%d:%02d:%02d', 24 + $this->next(70), $this->next(60), $this->next(60)),
                $i % 3 === 0 ? '' : 'https://example.com/manuals/item-' . $i,
                $i % 5 === 0 ? '' : 'item' . $i . '@example.com',
                sprintf('555-01%02d', $i % 100),
                $notes,
                $this->next(2) === 0 ? 'yes' : 'no',
            ];
        }

        return $rows;
    }

    /**
     * Rows written the way the CSV export plugin writes them: a UTF-8 byte
     * order mark, CRLF after every record, and a field quoted only when it
     * holds a comma, a quote, CR, or LF, or starts or ends with a space or
     * a tab (quotes doubled). Written here independently of the plugin.
     *
     * @param list<string> $headings
     * @param list<list<string>> $rows
     */
    public static function exportCsv(array $headings, array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        foreach ([$headings, ...$rows] as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $quote = $cell !== '' && (strpbrk($cell, ",\"\r\n") !== false || preg_match('/^[ \t]|[ \t]\z/', $cell) === 1);
                $cells[] = $quote ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
            }
            $out .= implode(',', $cells) . "\r\n";
        }

        return $out;
    }

    /**
     * The T-RT-1 file: canonicalRows() written as exportCsv() does.
     *
     * @param list<string> $rooms
     */
    public function canonicalCsv(int $count, array $rooms): string
    {
        return self::exportCsv(self::CANONICAL_HEADINGS, $this->canonicalRows($count, $rooms));
    }

    /**
     * The mock-up's synthetic file: SAMPLE_ROWS rows in SAMPLE_HEADINGS
     * order, the mock-up's two first rows, then seeded items. Rooms are M2
     * fixture rooms except rows 23 and 38 (SAMPLE_NEW_ROOMS); purchase
     * dates of SAMPLE_BAD_DATES are not dates; conditions are 1 to 5;
     * values have two decimals; row_id counts from 1.
     *
     * @return list<list<string>>
     */
    public function sampleRows(): array
    {
        $rooms = ['Kitchen', 'Garage', 'Study', 'Living room', 'Hallway', 'Bedroom'];
        $rows = [];
        for ($i = 1; $i <= self::SAMPLE_ROWS; $i++) {
            if ($i <= count(self::SAMPLE_FIRST)) {
                $row = self::SAMPLE_FIRST[$i - 1];
            } else {
                $date = sprintf('20%02d-%02d-%02d', 18 + $this->next(8), $this->next(12) + 1, $this->next(28) + 1);
                $bad = array_search($i, self::SAMPLE_BAD_DATES, true);
                $row = [
                    self::ADJECTIVES[$this->next(count(self::ADJECTIVES))] . ' ' . strtolower(self::NOUNS[$this->next(count(self::NOUNS))]) . ' ' . $i,
                    $rooms[$this->next(count($rooms))],
                    self::CATEGORIES[$this->next(count(self::CATEGORIES))],
                    is_int($bad) ? self::BAD_DATE_TEXT[$bad] : $date,
                    ($this->next(900) + 10) . '.00',
                    (string) ($this->next(5) + 1),
                    $this->next(3) === 0 ? 'Synthetic note for item ' . $i . '.' : '',
                ];
            }
            if ($i === 23 || $i === 38) {
                $row[1] = self::SAMPLE_NEW_ROOMS[$i === 23 ? 0 : 1];
            }
            $row[] = (string) $i;
            $rows[] = $row;
        }

        return $rows;
    }

    /** sampleRows() with the heading row, as `inventory-sample.csv`. */
    public function sampleCsv(): string
    {
        return self::csv(self::SAMPLE_HEADINGS, $this->sampleRows());
    }

    /**
     * The M2 data set plus the Samples table (see the class comment), built
     * through the core as the fixture's Admin. Returns the M2 fixture, the
     * Samples table key, its field keys by name, its record ids (the last
     * one is in the trash), and the attachment files written (path inside
     * the project folder => bytes).
     *
     * @return array{m2: M2Fixture, samples: string, fields: array<string, string>, ids: list<int>, files: array<string, string>}
     */
    public static function project(CoreFixture $core, ?Operations $ops = null): array
    {
        $ops ??= $core->operations();
        $m2 = M2Fixture::build($core, $ops);
        $admin = $core->actor('admin');
        $schema = $ops->schema();
        $p = $m2->project;
        $samples = $schema->createTable($admin, $p, 'Samples', 'sample', true)->key;
        $fields = ['Name' => $schema->design($admin, $p, $samples)->fields[0]->field->key];
        $add = static fn (string $name, string $type, array $options = []): string
            => $schema->addField($admin, $p, $samples, $name, $type, $options, false, '')->key;
        $fields['Weight'] = $add('Weight', 'number', ['decimals' => 2]);
        $fields['Price'] = $add('Price', 'currency', ['code' => 'EUR', 'minor_digits' => 2]);
        $fields['Opens at'] = $add('Opens at', 'time');
        $fields['Delivered'] = $add('Delivered', 'date_time');
        $fields['Run time'] = $add('Run time', 'duration');
        $fields['Contact'] = $add('Contact', 'email');
        $fields['Phone'] = $add('Phone', 'phone');
        $fields['Sample number'] = $add('Sample number', 'auto_number');
        $fields['File'] = $add('File', 'attachment');
        $fields['Room'] = $schema->addRelationField($admin, $p, $samples, 'Room', $m2->rooms, Cardinality::One, Cardinality::Many, 'Samples', false, '')->key;
        $fields['Related'] = $schema->addRelationField($admin, $p, $samples, 'Related', $samples, Cardinality::Many, Cardinality::Many, 'Related from', false, '')->key;

        $random = new self();
        $records = $ops->records();
        $rooms = array_values($m2->roomIds);
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $core->clock->advance(60);
            $text = [
                $fields['Name'] => 'Sample ' . $i . ' ' . strtolower(self::NOUNS[$random->next(count(self::NOUNS))]),
                $fields['Weight'] => ($random->next(50) + 1) . '.' . str_pad((string) $random->next(100), 2, '0', STR_PAD_LEFT),
                $fields['Price'] => $i % 6 === 0 ? '' : ($random->next(300) + 1) . '.' . str_pad((string) $random->next(100), 2, '0', STR_PAD_LEFT),
                $fields['Opens at'] => sprintf('%02d:%02d', $random->next(24), $random->next(60)),
                $fields['Delivered'] => sprintf('2025-%02d-%02dT%02d:%02d', $random->next(12) + 1, $random->next(28) + 1, $random->next(24), $random->next(60)),
                $fields['Run time'] => $random->next(5) . ':' . sprintf('%02d', $random->next(60)),
                $fields['Contact'] => $i % 4 === 0 ? '' : 'sample' . $i . '@example.com',
                $fields['Phone'] => sprintf('555-01%02d', $i),
            ];
            $id = $records->create($admin, $p, $samples, $text);
            $ids[] = $id;
            if ($i % 5 !== 0) {
                $records->setLinks($admin, $p, $samples, (string) $id, $fields['Room'], [$rooms[$random->next(count($rooms))]]);
            }
        }
        for ($i = 0; $i + 2 < count($ids); $i += 3) {
            $records->setLinks($admin, $p, $samples, (string) $ids[$i], $fields['Related'], [$ids[$i + 2], $ids[$i + 1]]);
        }
        $records->trash($admin, $p, $samples, (string) $ids[19]);

        // Attached files that exist in the project folder.
        $db = $core->projectDb($p);
        $folder = $core->projectFolder($p);
        $files = [];
        $attached = [[0, 'label.txt', 'text/plain'], [3, 'drawing.svg', 'image/svg+xml'], [3, 'notes.txt', 'text/plain'], [19, 'trashed.txt', 'text/plain']];
        foreach ($attached as $n => [$index, $name, $type]) {
            $bytes = 'Synthetic attachment ' . $n . " for TiffinDB tests.\n" . str_repeat('x', 100 * ($n + 1));
            $sha = hash('sha256', $bytes);
            $relative = 'attachments/' . substr($sha, 0, 2) . '/' . $sha;
            if (!is_dir($folder . '/' . dirname($relative))) {
                mkdir($folder . '/' . dirname($relative), 0750, true);
            }
            file_put_contents($folder . '/' . $relative, $bytes);
            $files[$relative] = $bytes;
            $db->write(static function (Database $db) use ($sha, $bytes, $type, $name, $fields, $ids, $index, $n): void {
                $db->run(
                    'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
                    [$sha, strlen($bytes), $type, $name, '2026-03-02T10:0' . $n . ':00Z'],
                );
                $db->run(
                    'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
                    [$fields['File'], $ids[$index], $db->lastInsertId(), $n + 1],
                );
            });
        }

        return ['m2' => $m2, 'samples' => $samples, 'fields' => $fields, 'ids' => $ids, 'files' => $files];
    }

    /**
     * Project "Parts Store" with one table Parts (Name, Weight (number, 2
     * decimals), Added (date), Price (currency USD)) and a self-relation
     * Fits (many to many, other side "Fitted by"): $count records, each
     * linked to the next two, inserted with plain SQL in one transaction.
     *
     * @return array{project: string, table: string, fields: array<string, string>}
     */
    public static function largeProject(CoreFixture $core, Operations $ops, int $count): array
    {
        $admin = $core->actor('admin');
        $p = $ops->projects()->create($admin, 'Parts Store', 'Parts')->key;
        $schema = $ops->schema();
        $table = $schema->tables($admin, $p)[0]->table->key;
        $fields = ['Name' => $schema->design($admin, $p, $table)->fields[0]->field->key];
        $fields['Weight'] = $schema->addField($admin, $p, $table, 'Weight', 'number', ['decimals' => 2], false, '')->key;
        $fields['Added'] = $schema->addField($admin, $p, $table, 'Added', 'date', [], false, '')->key;
        $fields['Price'] = $schema->addField($admin, $p, $table, 'Price', 'currency', ['code' => 'USD', 'minor_digits' => 2], false, '')->key;
        $fits = $schema->addRelationField($admin, $p, $table, 'Fits', $table, Cardinality::Many, Cardinality::Many, 'Fitted by', false, '');
        $fields['Fits'] = $fits->key;
        $relation = (string) $fits->options['relation'];

        $random = new self();
        $db = $core->projectDb($p);
        $now = '2026-03-02T10:00:00Z';
        $user = $admin->userKey;
        $db->write(static function (Database $db) use ($random, $count, $table, $fields, $relation, $now, $user): void {
            $insert = 'INSERT INTO ' . Identifier::table($table) . ' ("_created_at", "_created_by", "_updated_at", "_updated_by", '
                . Identifier::column($fields['Name']) . ', ' . Identifier::column($fields['Weight']) . ', '
                . Identifier::column($fields['Added']) . ', ' . Identifier::column($fields['Price']) . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
            for ($i = 1; $i <= $count; $i++) {
                $db->run($insert, [
                    $now, $user, $now, $user,
                    self::ADJECTIVES[$random->next(count(self::ADJECTIVES))] . ' part ' . $i,
                    ($random->next(5000) + 1) / 100,
                    sprintf('20%02d-%02d-%02d', 18 + $random->next(8), $random->next(12) + 1, $random->next(28) + 1),
                    $random->next(100000) + 1,
                ]);
            }
            $link = 'INSERT INTO ' . Identifier::joinTable($relation) . ' ("left_id", "right_id", "position") VALUES (?, ?, ?)';
            for ($i = 1; $i <= $count; $i++) {
                foreach ([1, 2] as $step) {
                    if ($i + $step <= $count) {
                        $db->run($link, [$i, $i + $step, $step]);
                    }
                }
            }
        });

        return ['project' => $p, 'table' => $table, 'fields' => $fields];
    }
}
