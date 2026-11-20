<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Formats;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\ExportSourceBuilder;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Tests\Plugins\Json\JsonFixture;

require_once __DIR__ . '/../plugins/json/JsonFixture.php';

/**
 * T-JSON-1 (M3 spec section 15.5): the JSON export of the seeded synthetic
 * project (JsonFixture, from IoFixture::SEED) validates against
 * docs/formats/json-export.md. Member names and their order are read from
 * the document's example; the JSON form of each field type and its empty
 * value are read from the table of section 4. Records, links, and
 * attachments are compared with what plain SQL reads from the project file;
 * records in the trash and links to them are absent. Also: UTF-8 without a
 * byte order mark, U+2028 and U+2029 escaped, `/` not escaped, invalid
 * stored UTF-8 written as U+FFFD, one line per record, and the same bytes
 * from two separate builds of the fixture.
 *
 * T-JSON-3 (M4 spec section 12.4): `collections` is the last member, each
 * collection and rule has the members of the example of section 7 in its
 * order, values equal what plain SQL reads from `_tiffin_collections`
 * (rules as stored, broken ones included), one collection per line, and
 * the CSV and XLSX exporters write the same bytes with or without
 * collections.
 */
#[CoversNothing]
final class JsonExportFormatTest extends TestCase
{
    use JsonFixture;

    private const DOCUMENT = __DIR__ . '/../../docs/formats/json-export.md';

    private string $json;
    /** @var array<string, mixed> */
    private array $doc;

    protected function setUp(): void
    {
        $this->buildInventory();
        $this->json = $this->export($this->source());
        $this->doc = self::decode($this->json);
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return array<string, mixed> the example of section 1 */
    private static function example(): array
    {
        $text = (string) file_get_contents(self::DOCUMENT);
        self::assertSame(1, preg_match('/```json\n(.*?)\n```/s', $text, $m), 'The document has a JSON example.');

        return self::decode($m[1]);
    }

    /**
     * The table of section 4: type => [JSON value description, empty description].
     *
     * @return array<string, array{string, string}>
     */
    private static function typeTable(): array
    {
        $text = (string) file_get_contents(self::DOCUMENT);
        self::assertSame(1, preg_match('/\n## 4\. .*?\n## 5\./s', $text, $section));
        $rows = [];
        foreach (explode("\n", $section[0]) as $line) {
            if (preg_match('/^\| ((?:`[a-z_]+`(?:, )?)+) \| (.+) \| (.+) \|$/', $line, $m) !== 1 || $m[2] === 'JSON value') {
                continue;
            }
            preg_match_all('/`([a-z_]+)`/', $m[1], $types);
            foreach ($types[1] as $type) {
                $rows[$type] = [$m[2], $m[3]];
            }
        }

        return $rows;
    }

    public function testMembersAndTheirOrderFollowTheDocumentsExample(): void
    {
        $example = self::example();
        $doc = $this->doc;
        self::assertSame(array_keys($example), array_keys($doc));
        self::assertSame(array_keys($example['project']), array_keys($doc['project']));
        self::assertCount(2, $doc['relations']);
        foreach ($doc['relations'] as $relation) {
            self::assertSame(array_keys($example['relations'][0]), array_keys($relation));
            self::assertSame(array_keys($example['relations'][0]['left']), array_keys($relation['left']));
            self::assertSame(array_keys($example['relations'][0]['right']), array_keys($relation['right']));
        }
        self::assertNotSame([], $doc['attachments']);
        foreach ($doc['attachments'] as $attachment) {
            self::assertSame(array_keys($example['attachments'][0]), array_keys($attachment));
        }
        self::assertCount(2, $doc['tables']);
        foreach ($doc['tables'] as $table) {
            self::assertSame(array_keys($example['tables'][0]), array_keys($table));
            $fieldKeys = [];
            foreach ($table['fields'] as $field) {
                self::assertSame(array_keys($example['tables'][0]['fields'][0]), array_keys($field));
                $fieldKeys[] = $field['key'];
            }
            self::assertNotSame([], $table['records']);
            foreach ($table['records'] as $record) {
                self::assertSame(array_keys($example['tables'][0]['records'][0]), array_keys($record));
                self::assertSame($fieldKeys, array_keys($record['values']), 'One value per field, in field order.');
            }
        }
    }

    public function testTopLevelAndProjectValues(): void
    {
        $doc = $this->doc;
        self::assertSame('tiffindb-json', $doc['format']);
        self::assertSame(1, $doc['format_version']);
        self::assertSame(self::APP_VERSION, $doc['exported_with']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $doc['exported_at']);
        self::assertSame($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'), $doc['exported_at']);
        self::assertSame(self::PROJECT_KEY, $doc['project']['key']);
        self::assertMatchesRegularExpression('/^[a-z2-7]{12}\z/', $doc['project']['key']);
        self::assertSame('Example Project', $doc['project']['name']);
        self::assertSame((int) $this->db->value('PRAGMA user_version'), $doc['project']['file_format_version']);
        self::assertSame(self::START, $doc['project']['created_at']);
        foreach (['created_at', 'updated_at'] as $member) {
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $doc['project'][$member]);
        }
    }

    public function testEveryValueHasTheFormOfItsTypeInSection4(): void
    {
        $table = self::typeTable();
        $known = FieldTypes::standard()->ids();
        sort($known);
        $documented = array_keys($table);
        sort($documented);
        self::assertSame($known, $documented, 'Section 4 lists every field type, and no other.');

        $tables = $this->doc['tables'];
        $liveIds = [];
        foreach ($tables as $t) {
            $liveIds[$t['key']] = array_column($t['records'], 'id');
        }
        $attachmentIds = array_column($this->doc['attachments'], 'id');
        $relationTables = [];
        foreach ($this->doc['relations'] as $relation) {
            $relationTables[$relation['left']['field']] = $relation['right']['table'];
            $relationTables[$relation['right']['field']] = $relation['left']['table'];
        }
        $object = json_decode($this->json, false, 64, JSON_THROW_ON_ERROR);

        $seen = [];
        $emptySeen = [];
        foreach ($tables as $ti => $t) {
            foreach ($t['fields'] as $fi => $field) {
                $type = $field['type'];
                self::assertArrayHasKey($type, $table, 'A documented type: ' . $type);
                self::assertInstanceOf(\stdClass::class, $object->tables[$ti]->fields[$fi]->options, 'Options are an object.');
                self::assertIsBool($field['required']);
                self::assertIsString($field['help']);
                [$form, $empty] = $table[$type];
                foreach ($t['records'] as $record) {
                    $value = $record['values'][$field['key']];
                    $where = $t['name'] . ' ' . $record['id'] . ' ' . $type;
                    if ($value === null || $value === []) {
                        $emptySeen[$type] = true;
                        self::assertSame(str_contains($empty, '`[]`') ? [] : null, $value, 'Empty value of ' . $where);
                        self::assertStringNotContainsString('never empty', $empty, $where);
                        continue;
                    }
                    $seen[$type] = true;
                    $this->assertForm($form, $field, $value, $where, $liveIds, $attachmentIds, $relationTables);
                }
            }
        }
        sort($known);
        $seenTypes = array_keys($seen);
        sort($seenTypes);
        self::assertSame($known, $seenTypes, 'The fixture has a value of every type.');
        foreach ($table as $type => [, $empty]) {
            // TiffinDB assigns every auto_number, so the fixture cannot hold an empty one.
            if (!str_contains($empty, 'never empty') && $type !== 'auto_number') {
                self::assertArrayHasKey($type, $emptySeen, 'The fixture has an empty value of ' . $type);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, list<int>> $liveIds
     * @param list<int> $attachmentIds
     * @param array<string, string> $relationTables
     */
    private function assertForm(string $form, array $field, mixed $value, string $where, array $liveIds, array $attachmentIds, array $relationTables): void
    {
        $options = $field['options'];
        if (str_starts_with($form, 'String')) {
            self::assertIsString($value, $where);
            self::assertNotSame('', $value, $where);
            $patterns = ['`YYYY-MM-DDTHH:MM:SS`' => '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\z/', '`YYYY-MM-DD`' => '/^\d{4}-\d\d-\d\d\z/', '`HH:MM:SS`' => '/^\d\d:\d\d:\d\d\z/'];
            foreach ($patterns as $template => $pattern) {
                if (str_contains($form, $template)) {
                    self::assertMatchesRegularExpression($pattern, $value, $where);
                    break;
                }
            }
            if (str_contains($form, 'exactly `minor_digits` decimals')) {
                $digits = $options['minor_digits'];
                self::assertMatchesRegularExpression('/^-?(0|[1-9][0-9]*)' . ($digits === 0 ? '' : '\.[0-9]{' . $digits . '}') . '\z/', $value, $where);
            }
            if (!str_contains($form, 'may hold `\n`')) {
                self::assertStringNotContainsString("\n", $value, $where);
            }
            if (str_contains($form, 'the chosen label')) {
                self::assertContains($value, array_column($options['choices'], 'label'), $where);
            }

            return;
        }
        if (str_starts_with($form, 'Number')) {
            self::assertTrue(is_int($value) || is_float($value), $where);
            self::assertSame(1, preg_match('/"' . $field['key'] . '":(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?)[,}]/', $this->json), 'A plain number token: ' . $where);

            return;
        }
        if (str_starts_with($form, 'Integer')) {
            self::assertIsInt($value, $where);
            if (str_contains($form, 'from 1 to `options.max`')) {
                self::assertGreaterThanOrEqual(1, $value, $where);
                self::assertLessThanOrEqual($options['max'], $value, $where);
            }

            return;
        }
        if (str_starts_with($form, '`true` or `false`')) {
            self::assertIsBool($value, $where);

            return;
        }
        if (str_starts_with($form, 'Array of the `id`s of the linked records')) {
            self::assertTrue(array_is_list($value), $where);
            foreach ($value as $id) {
                self::assertContains($id, $liveIds[$relationTables[$field['key']]], 'A live linked record: ' . $where);
            }

            return;
        }
        if (str_starts_with($form, 'Array of attachment `id`s')) {
            foreach ($value as $id) {
                self::assertContains($id, $attachmentIds, 'A listed attachment: ' . $where);
            }

            return;
        }
        self::fail('The form "' . $form . '" is not one this test knows.');
    }

    public function testRecordsLinksAndAttachmentsMatchTheProjectFileAndLeaveOutTheTrash(): void
    {
        $byKey = [];
        foreach ($this->doc['tables'] as $t) {
            $byKey[$t['key']] = $t;
            $live = array_map('intval', array_column($this->db->all('SELECT "id" FROM ' . Identifier::table($t['key']) . ' WHERE "_trashed_at" IS NULL ORDER BY "id"'), 'id'));
            self::assertSame($live, array_column($t['records'], 'id'), 'Every live record, by id, and only those: ' . $t['name']);
        }
        self::assertSame(['Rooms', 'Items'], array_column($this->doc['tables'], 'name'));
        $trashed = [$this->rooms->key => $this->trashedRooms, $this->items->key => $this->trashedItems];
        foreach ($trashed as $tableKey => $ids) {
            self::assertNotSame([], $ids);
            foreach ($ids as $id) {
                self::assertNotContains($id, array_column($byKey[$tableKey]['records'], 'id'));
            }
        }

        foreach ($this->doc['relations'] as $relation) {
            $rows = $this->db->all(
                'SELECT "j"."left_id" AS "l", "j"."right_id" AS "r" FROM ' . Identifier::joinTable($relation['key']) . ' AS "j"'
                . ' JOIN ' . Identifier::table($relation['left']['table']) . ' AS "a" ON "a"."id" = "j"."left_id" AND "a"."_trashed_at" IS NULL'
                . ' JOIN ' . Identifier::table($relation['right']['table']) . ' AS "b" ON "b"."id" = "j"."right_id" AND "b"."_trashed_at" IS NULL'
                . ' ORDER BY "j"."left_id", "j"."position", "j"."right_id"',
            );
            $expected = array_map(static fn (array $row): string => $row['l'] . '-' . $row['r'], $rows);
            self::assertNotSame([], $expected, 'The fixture links records of ' . $relation['key']);
            $fromLeft = [];
            foreach ($byKey[$relation['left']['table']]['records'] as $record) {
                foreach ($record['values'][$relation['left']['field']] as $other) {
                    $fromLeft[] = $record['id'] . '-' . $other;
                }
            }
            self::assertSame($expected, $fromLeft, 'Left side, in link order.');
            $fromRight = [];
            foreach ($byKey[$relation['right']['table']]['records'] as $record) {
                foreach ($record['values'][$relation['right']['field']] as $other) {
                    $fromRight[] = $other . '-' . $record['id'];
                }
            }
            sort($expected);
            sort($fromRight);
            self::assertSame($expected, $fromRight, 'The right side describes the same links.');
            $all = $this->db->value('SELECT COUNT(*) FROM ' . Identifier::joinTable($relation['key']));
            self::assertGreaterThan(count($expected), (int) $all, 'The fixture has links to records in the trash.');
        }

        $linked = $this->db->all(
            'SELECT DISTINCT "a"."id", "a"."sha256", "a"."size", "a"."media_type", "a"."original_name", "a"."created_at" FROM "_tiffin_attachments" AS "a"'
            . ' JOIN "_tiffin_attachment_links" AS "l" ON "l"."attachment_id" = "a"."id"'
            . ' JOIN ' . $this->items->sql() . ' AS "r" ON "r"."id" = "l"."record_id" AND "r"."_trashed_at" IS NULL ORDER BY "a"."id"',
        );
        $expected = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'sha256' => $row['sha256'], 'size' => (int) $row['size'], 'media_type' => $row['media_type'],
            'original_name' => $row['original_name'], 'created_at' => $row['created_at'],
        ], $linked);
        self::assertSame($expected, $this->doc['attachments'], 'Attachments linked from live records: metadata only.');
        self::assertLessThan((int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_attachments"'), count($expected), 'An attachment of a trashed record is left out.');
        foreach ($this->doc['attachments'] as $attachment) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $attachment['sha256']);
        }
        foreach ($byKey[$this->items->key]['records'] as $record) {
            $rows = $this->db->all('SELECT "attachment_id" FROM "_tiffin_attachment_links" WHERE "field_key" = ? AND "record_id" = ? ORDER BY "position", "attachment_id"', [$this->f['attachment']->key, $record['id']]);
            self::assertSame(array_map('intval', array_column($rows, 'attachment_id')), $record['values'][$this->f['attachment']->key]);
        }
    }

    public function testValuesEqualTheStoredValues(): void
    {
        $items = $this->doc['tables'][1];
        $stored = [];
        foreach ($this->db->all('SELECT * FROM ' . $this->items->sql() . ' WHERE "_trashed_at" IS NULL ORDER BY "id"') as $row) {
            $stored[(int) $row['id']] = $row;
        }
        foreach ($items['records'] as $record) {
            $row = $stored[$record['id']];
            $v = $record['values'];
            $col = static fn (string $short, array $f): mixed => $row[Identifier::columnName($f[$short]->key)];
            $minor = $col('currency', $this->f);
            self::assertSame($minor === null ? null : sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv(abs((int) $minor), 100), abs((int) $minor) % 100), $v[$this->f['currency']->key]);
            $number = $col('number', $this->f);
            self::assertEquals($number === null ? null : (float) $number, $v[$this->f['number']->key]);
            self::assertSame($col('duration', $this->f) === null ? null : (int) $col('duration', $this->f), $v[$this->f['duration']->key]);
            self::assertSame((int) $col('checkbox', $this->f) === 1, $v[$this->f['checkbox']->key]);
            foreach (['date', 'time', 'date_time', 'choice', 'url', 'email', 'phone', 'long_text'] as $short) {
                self::assertSame($col($short, $this->f), $v[$this->f[$short]->key], $short . ' of ' . $record['id']);
            }
            self::assertSame($record['id'] === 3 ? self::BAD_UTF8_WRITTEN : $col('name', $this->f), $v[$this->f['name']->key]);
            self::assertSame($row['_created_by'], $record['created_by']);
            self::assertSame($row['_updated_at'], $record['updated_at']);
        }
    }

    public function testTextIsUtf8WithTheDocumentedEscapes(): void
    {
        $json = $this->json;
        self::assertSame(1, preg_match('//u', $json), 'Valid UTF-8.');
        self::assertStringStartsNotWith("\u{FEFF}", $json);
        self::assertStringStartsWith('{"format":"tiffindb-json",' . "\n", $json);
        self::assertStringEndsWith("}\n", $json);
        self::assertStringContainsString('sep \\u2028 para \\u2029 na' . "\u{EF}" . 've ' . "\u{1F9C0}" . '\\nnext line', $json, 'Line and paragraph separators escaped; other characters as UTF-8.');
        self::assertStringNotContainsString("\u{2028}", $json);
        self::assertStringNotContainsString("\u{2029}", $json);
        self::assertStringContainsString('Quote \\" back \\\\ slash / sep', $json, 'Quotes and backslashes escaped, `/` not.');
        self::assertStringContainsString('"https://example.com/items/', $json);
        self::assertStringContainsString('"original_name":"photo \\"front\\".png"', $json);
        $first = $this->doc['tables'][1]['records'][0]['values'][$this->f['long_text']->key];
        self::assertSame(self::ESCAPES . "\nnext line", $first);
        self::assertStringContainsString(json_encode(self::BAD_UTF8_WRITTEN, JSON_UNESCAPED_UNICODE), $json, 'Invalid UTF-8 is written as U+FFFD.');
        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F]/', $json, 'No raw control characters; line breaks only between members and records.');

        // One line per top-level member, relation, attachment, and record.
        $lines = explode("\n", rtrim($json, "\n"));
        $records = count($this->doc['tables'][0]['records']) + count($this->doc['tables'][1]['records']);
        self::assertSame($records, count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '{"id":') && str_contains($l, '"values":'))));
        foreach ($lines as $line) {
            self::assertLessThan(8192, strlen($line));
        }
    }

    public function testTheSameProjectGivesTheSameBytes(): void
    {
        self::assertSame($this->json, $this->export($this->source(), 'again.json'), 'Exporting twice.');
        $hash = hash('sha256', $this->json);
        $this->tearDownProject();
        $this->trashedItems = [];
        $this->trashedRooms = [];
        $this->f = [];
        $this->buildInventory();
        self::assertSame($hash, hash('sha256', $this->export($this->source())), 'A second build of the seeded fixture.');
    }

    /** @return array<string, mixed> the example of section 7 */
    private static function collectionsExample(): array
    {
        $text = (string) file_get_contents(self::DOCUMENT);
        self::assertSame(1, preg_match('/\n## 7\. Collections\n(.*?)\n## 8\./s', $text, $section));
        self::assertSame(1, preg_match('/```json\n(.*?)\n```/s', $section[1], $m), 'Section 7 has a JSON example.');

        return self::decode($m[1]);
    }

    public function testCollectionsAreTheLastMemberWithTheMembersOfSection7(): void
    {
        $doc = $this->doc;
        self::assertSame('collections', array_key_last($doc));
        self::assertSame('collections', array_key_last(self::example()), 'The example of section 1 shows the member last.');
        $example = self::collectionsExample()['collections'][0];
        self::assertCount(3, $doc['collections']);
        foreach ($doc['collections'] as $collection) {
            self::assertSame(array_keys($example), array_keys($collection));
            foreach ($collection['rules'] as $rule) {
                self::assertSame(array_keys($example['rules'][0]), array_keys($rule));
            }
        }

        // Values equal the plain SQL reading, in sidebar order; rules exactly as stored.
        $rows = $this->db->all('SELECT "key", "table_key", "name", "match_mode", "rules", "sort", "created_at", "updated_at" FROM "_tiffin_collections" ORDER BY "position"');
        $expected = [];
        foreach ($rows as $row) {
            $expected[] = [
                'key' => $row['key'], 'table' => $row['table_key'], 'name' => $row['name'], 'match' => $row['match_mode'],
                'rules' => json_decode((string) $row['rules'], true, 8, JSON_THROW_ON_ERROR), 'sort' => $row['sort'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ];
        }
        self::assertSame($expected, $doc['collections']);
        self::assertSame(['Tools in use', 'No longer working', 'Every room'], array_column($doc['collections'], 'name'));
        self::assertSame([$this->items->key, $this->items->key, $this->rooms->key], array_column($doc['collections'], 'table'));
        self::assertSame(['all', 'any', 'all'], array_column($doc['collections'], 'match'));
        self::assertSame([$this->f['date']->key . '.desc', null, 'added.asc'], array_column($doc['collections'], 'sort'));

        // Broken rules are written too: a deleted field and a removed choice.
        $broken = $doc['collections'][1]['rules'];
        self::assertSame(['field' => 'z7zz7zz7zz7z', 'op' => 'more', 'values' => ['10']], $broken[0]);
        self::assertSame(['field' => $this->f['choice']->key, 'op' => 'is', 'values' => ['zzzzzzzzzzzz']], $broken[1]);
        self::assertSame([], $doc['collections'][2]['rules']);

        // One line per collection, after the tables.
        $lines = explode("\n", rtrim($this->json, "\n"));
        self::assertSame('"collections":[', $lines[count($lines) - 5]);
        foreach ([1, 2, 3] as $n) {
            self::assertStringStartsWith('{"key":"' . $doc['collections'][$n - 1]['key'] . '","table":', $lines[count($lines) - 5 + $n]);
        }
        self::assertSame(']}', end($lines));
    }

    public function testCollectionsOfTablesOutsideTheExportAreLeftOut(): void
    {
        $doc = self::decode($this->export(ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $this->rooms->key, $this->clock, self::APP_VERSION), 'rooms.json'));
        self::assertSame(['Every room'], array_column($doc['collections'], 'name'));
    }

    private function exporterFor(string $ref): Exporter
    {
        $root = dirname(__DIR__, 2) . '/plugins';
        foreach ((new PluginScanner($root))->scan([$ref => true]) as $plugin) {
            if ($plugin->ref() === $ref) {
                self::assertSame(PluginStatus::Enabled, $plugin->status, (string) $plugin->brokenReason);

                return (new PluginLoader($root))->exporter($plugin);
            }
        }
        self::fail('The ' . $ref . ' plugin was not found.');
    }

    public function testCsvAndXlsxOutputDoesNotChangeWithCollections(): void
    {
        $refs = ['export/csv' => 'table', 'export/xlsx' => 'project'];
        // The XLSX writer needs a `tmp` folder beside its target, as in a job folder.
        mkdir($this->temp->path('tmp'), 0750);
        $with = [];
        foreach ($refs as $ref => $scope) {
            $exporter = $this->exporterFor($ref);
            $tableKey = $scope === 'table' ? $this->items->key : null;
            $path = $this->temp->path(str_replace('/', '-', $ref) . '-with');
            $exporter->write(ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $tableKey, $this->clock, self::APP_VERSION), $exporter->settings($scope), $path);
            $with[$ref] = (string) file_get_contents($path);
        }
        $this->db->run('DELETE FROM "_tiffin_collections"');
        foreach ($refs as $ref => $scope) {
            $exporter = $this->exporterFor($ref);
            $tableKey = $scope === 'table' ? $this->items->key : null;
            $path = $this->temp->path(str_replace('/', '-', $ref) . '-without');
            $exporter->write(ExportSourceBuilder::build($this->db, $this->schema(), $this->types, $tableKey, $this->clock, self::APP_VERSION), $exporter->settings($scope), $path);
            self::assertNotSame('', $with[$ref]);
            self::assertSame(hash('sha256', $with[$ref]), hash('sha256', (string) file_get_contents($path)), $ref);
        }
    }
}
