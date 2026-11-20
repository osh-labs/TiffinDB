<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-3 (M3 spec section 15.7): the JSON export of the fixture project
 * (IoFixture::project: the M2 data set and Samples, with every field type,
 * a self-relation, records and a file in the trash, and files that exist)
 * made through ExportOperations, decoded with PHP's own JSON parser (no
 * TiffinDB code) and checked against docs/formats/json-export.md read
 * here: member names and order from the document's example, the value
 * form of each type from its section 4 table, and every value, link,
 * attachment, field, relation, and table compared with what plain SQL
 * reads from the project file. Also: no byte order mark, one line per
 * record, and no exponent in any number.
 */
#[CoversNothing]
final class JsonRoundTripTest extends RoundTripCase
{
    private const DOCUMENT = __DIR__ . '/../../docs/formats/json-export.md';

    public function testJsonExportEqualsThePlainSqlReading(): void
    {
        $fx = IoFixture::project($this->core, $this->ops);
        $p = $fx['m2']->project;
        $json = $this->export($p, 'export/json', null);
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $json);

        $example = self::example();
        self::assertSame(array_keys($example), array_keys($doc), 'Top-level members in the document\'s order.');
        self::assertSame(array_keys($example['project']), array_keys($doc['project']));
        self::assertSame(['tiffindb-json', 1, '0.2.0-dev'], [$doc['format'], $doc['format_version'], $doc['exported_with']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $doc['exported_at']);

        $db = $this->core->projectDb($p);
        $meta = array_column($db->all('SELECT key, value FROM _tiffin_meta'), 'value', 'key');
        self::assertSame(
            [$meta['project_key'], $meta['project_name'], $meta['created_at'], $meta['updated_at'], 4],
            [$doc['project']['key'], $doc['project']['name'], $doc['project']['created_at'], $doc['project']['updated_at'], $doc['project']['file_format_version']],
        );

        // Relations.
        $relations = [];
        foreach ($db->all('SELECT * FROM _tiffin_relations ORDER BY key') as $r) {
            $relations[] = [
                'key' => $r['key'],
                'left' => ['table' => $r['left_table'], 'field' => $r['left_field'], 'max' => $r['left_max']],
                'right' => ['table' => $r['right_table'], 'field' => $r['right_field'], 'max' => $r['right_max']],
            ];
        }
        $actual = $doc['relations'];
        usort($actual, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        self::assertSame($relations, $actual);
        self::assertSame(array_keys($example['relations'][0]), array_keys($doc['relations'][0]));
        $self = array_values(array_filter($doc['relations'], static fn (array $r): bool => $r['left']['table'] === $r['right']['table']));
        self::assertCount(1, $self, 'The self-relation of Samples.');

        // Tables, fields, and records.
        $tables = $db->all('SELECT * FROM _tiffin_tables ORDER BY position, key');
        self::assertSame(array_column($tables, 'key'), array_column($doc['tables'], 'key'), 'Tables in the order TiffinDB shows them.');
        $types = self::typeTable();
        $liveAttachments = [];
        $seenTypes = [];
        foreach ($tables as $i => $t) {
            $table = $doc['tables'][$i];
            self::assertSame(array_keys($example['tables'][0]), array_keys($table));
            self::assertSame([$t['name'], $t['item_name'], $t['title_field']], [$table['name'], $table['item_name'], $table['title_field']]);
            $fields = $db->all('SELECT * FROM _tiffin_fields WHERE table_key = ? ORDER BY position, key', [$t['key']]);
            $expectedFields = [];
            foreach ($fields as $f) {
                $expectedFields[] = [
                    'key' => $f['key'], 'name' => $f['name'], 'type' => $f['type'],
                    'options' => json_decode((string) $f['options'], true, 16, JSON_THROW_ON_ERROR),
                    'required' => (int) $f['required'] === 1, 'help' => $f['help'],
                ];
                $seenTypes[$f['type']] = true;
            }
            self::assertSame(array_keys($example['tables'][0]['fields'][0]), array_keys($table['fields'][0]));
            self::assertEquals($expectedFields, $table['fields'], "{$t['name']}: fields.");

            $rows = $db->all('SELECT * FROM "t_' . $t['key'] . '" WHERE "_trashed_at" IS NULL ORDER BY "id"');
            self::assertSame(array_map(static fn (array $r): int => (int) $r['id'], $rows), array_column($table['records'], 'id'), "{$t['name']}: live records by id.");
            foreach ($rows as $n => $row) {
                $record = $table['records'][$n];
                self::assertSame(array_keys($example['tables'][0]['records'][0]), array_keys($record));
                self::assertSame(
                    [$row['_created_at'], $row['_created_by'], $row['_updated_at'], $row['_updated_by']],
                    [$record['created_at'], $record['created_by'], $record['updated_at'], $record['updated_by']],
                );
                self::assertSame(array_column($fields, 'key'), array_keys($record['values']), 'One value per field, in field order.');
                foreach ($fields as $f) {
                    $expected = self::expected($db, $f, $row, $types);
                    self::assertSame($expected, $record['values'][$f['key']], "{$t['name']} {$row['id']}: {$f['name']} ({$f['type']}).");
                    if ($f['type'] === 'attachment') {
                        foreach ($expected as $id) {
                            $liveAttachments[$id] = true;
                        }
                    }
                }
            }
        }
        foreach (['text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'checkbox', 'choice', 'rating', 'url', 'email', 'phone', 'auto_number', 'relation', 'attachment'] as $type) {
            self::assertArrayHasKey($type, $seenTypes, "The fixture has a {$type} field.");
            self::assertArrayHasKey($type, $types, "The document describes {$type}.");
        }

        // Attachments: only files attached to live records, by id.
        ksort($liveAttachments);
        $expected = [];
        foreach (array_keys($liveAttachments) as $id) {
            $a = $db->one('SELECT * FROM _tiffin_attachments WHERE id = ?', [$id]);
            self::assertNotNull($a);
            $expected[] = ['id' => (int) $a['id'], 'sha256' => $a['sha256'], 'size' => (int) $a['size'], 'media_type' => $a['media_type'], 'original_name' => $a['original_name'], 'created_at' => $a['created_at']];
        }
        self::assertSame($expected, $doc['attachments']);
        self::assertNotContains('trashed.txt', array_column($doc['attachments'], 'original_name'), 'A file attached only to a record in the trash is left out.');
        self::assertSame(array_keys($example['attachments'][0]), array_keys($doc['attachments'][0]));

        // Layout: one line per record; numbers never with an exponent.
        $records = array_sum(array_map(static fn (array $t): int => count($t['records']), $doc['tables']));
        self::assertSame($records, preg_match_all('/^\{"id":\d+,"created_at":.*"values":\{.*\}\},?$/m', $json), 'Each record on a line of its own.');
        self::assertSame(0, preg_match('/[:\[,] ?-?\d+(\.\d+)?[eE][+-]?\d/', $json), 'No exponent.');
    }

    /**
     * The JSON value a field's stored value must have (document section 4),
     * computed from plain SQL.
     *
     * @param array<string, mixed> $field a _tiffin_fields row
     * @param array<string, mixed> $row a user table row
     * @param array<string, array{string, string}> $types the document's table
     */
    private static function expected(Database $db, array $field, array $row, array $types): mixed
    {
        $type = (string) $field['type'];
        $options = json_decode((string) $field['options'], true, 16, JSON_THROW_ON_ERROR);
        $id = (int) $row['id'];
        if ($type === 'relation') {
            self::assertSame('`[]`', $types[$type][1]);
            $relation = $db->one('SELECT * FROM _tiffin_relations WHERE key = ?', [$options['relation']]);
            self::assertNotNull($relation);
            [$mine, $other, $otherTable] = $options['side'] === 'left' ? ['left_id', 'right_id', $relation['right_table']] : ['right_id', 'left_id', $relation['left_table']];
            $links = $db->all(
                'SELECT j."' . $other . '" AS id FROM "j_' . $relation['key'] . '" AS j JOIN "t_' . $otherTable . '" AS o ON o."id" = j."' . $other . '"'
                . ' WHERE j."' . $mine . '" = ? AND o."_trashed_at" IS NULL ORDER BY j."position", j."' . $other . '"',
                [$id],
            );

            return array_map(static fn (array $l): int => (int) $l['id'], $links);
        }
        if ($type === 'attachment') {
            $links = $db->all('SELECT attachment_id FROM _tiffin_attachment_links WHERE field_key = ? AND record_id = ? ORDER BY position, attachment_id', [$field['key'], $id]);

            return array_map(static fn (array $l): int => (int) $l['attachment_id'], $links);
        }
        $stored = $row['f_' . $field['key']];
        if ($type === 'checkbox') {
            self::assertSame('never empty', $types[$type][1]);

            return (int) $stored === 1;
        }
        self::assertSame('`null`', $types[$type][1], "{$type}: empty is null.");
        if ($stored === null) {
            return null;
        }

        return match ($type) {
            'number' => (float) $stored,
            'currency' => self::minorUnits((int) $stored, (int) $options['minor_digits']),
            'duration', 'rating', 'auto_number' => (int) $stored,
            default => (string) $stored,
        };
    }

    /** "329.00" from 32900 with 2 digits, with integer arithmetic only. */
    private static function minorUnits(int $minor, int $digits): string
    {
        if ($digits === 0) {
            return (string) $minor;
        }
        $sign = $minor < 0 ? '-' : '';
        $text = str_pad((string) abs($minor), $digits + 1, '0', STR_PAD_LEFT);

        return $sign . substr($text, 0, -$digits) . '.' . substr($text, -$digits);
    }

    /** @return array<string, mixed> the example of section 1 */
    private static function example(): array
    {
        $text = (string) file_get_contents(self::DOCUMENT);
        self::assertSame(1, preg_match('/```json\n(.*?)\n```/s', $text, $m));
        $example = json_decode($m[1], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($example);

        return $example;
    }

    /** @return array<string, array{string, string}> type => [JSON value, empty], from section 4 */
    private static function typeTable(): array
    {
        $text = (string) file_get_contents(self::DOCUMENT);
        self::assertSame(1, preg_match('/\n## 4\. .*?\n## 5\./s', $text, $section));
        $rows = [];
        foreach (explode("\n", $section[0]) as $line) {
            if (preg_match('/^\| ((?:`[a-z_]+`(?:, )?)+) \| (.+) \| (.+) \|$/', $line, $m) === 1) {
                preg_match_all('/`([a-z_]+)`/', $m[1], $names);
                foreach ($names[1] as $name) {
                    $rows[$name] = [$m[2], $m[3]];
                }
            }
        }

        return $rows;
    }
}
