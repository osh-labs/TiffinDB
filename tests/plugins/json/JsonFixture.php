<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Json;

use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\ExportSourceBuilder;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Tests\Core\Schema\ProjectFixture;
use TiffinDB\Tests\Support\IoFixture;

require_once __DIR__ . '/../../core/schema/ProjectFixture.php';

/**
 * The JSON export plugin, loaded the way the core loads it (scanner, then
 * loader, from the repository's plugins/ folder), and a synthetic "Home
 * Inventory" project built from IoFixture::SEED: Rooms and Items, every
 * field type, a relation between the tables and one from Items to itself,
 * attachments, records and links in the trash, and text that needs
 * escaping (quotes, backslash, slash, U+2028, U+2029, a line break, non-ASCII
 * letters, an emoji, and a stored value with invalid UTF-8). Keys come from
 * SeededRandom and times from FixedClock, so the same build always gives the
 * same file.
 */
trait JsonFixture
{
    use ProjectFixture;

    private const APP_VERSION = '0.3.0-dev';
    private const ITEM_COUNT = 40;
    /** Field types of Items in the order they are added after Name. */
    private const ITEM_TYPES = [
        'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'checkbox', 'choice', 'rating',
        'url', 'email', 'phone', 'auto_number', 'attachment',
    ];
    /** Stored invalid UTF-8 (an `é` cut after its first byte), and what the export writes for it. */
    private const BAD_UTF8 = "Caf\xC3 sign";
    private const BAD_UTF8_WRITTEN = "Caf\u{FFFD} sign";
    private const ESCAPES = "Quote \" back \\ slash / sep \u{2028} para \u{2029} na\u{EF}ve \u{1F9C0}";

    private TableDef $rooms;
    private TableDef $items;
    /** @var array<string, FieldDef> short name => field of Items */
    private array $f = [];
    private FieldDef $roomName;
    /** @var list<int> */
    private array $trashedRooms = [];
    /** @var list<int> */
    private array $trashedItems = [];

    private function exporter(): Exporter
    {
        $root = dirname(__DIR__, 3) . '/plugins';
        foreach ((new PluginScanner($root))->scan(['export/json' => true]) as $plugin) {
            if ($plugin->ref() === 'export/json') {
                self::assertSame(PluginStatus::Enabled, $plugin->status, (string) $plugin->brokenReason);

                return (new PluginLoader($root))->exporter($plugin);
            }
        }
        self::fail('The export/json plugin was not found.');
    }

    /** Writes $source with the plugin and returns the file's bytes. */
    private function export(ExportSource $source, string $name = 'export.json'): string
    {
        $path = $this->temp->path($name);
        $exporter = $this->exporter();
        $exporter->write($source, $exporter->settings('project'), $path);

        return (string) file_get_contents($path);
    }

    private function source(): ExportSource
    {
        return ExportSourceBuilder::build($this->db, $this->schema(), $this->types, null, $this->clock, self::APP_VERSION);
    }

    private function buildInventory(string $seed = 'tiffindb-json-export-1'): void
    {
        $this->setUpProject($seed);
        $editor = $this->editor();
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->items = $editor->createTable('Items', 'item', true);
        $this->roomName = $this->schema()->fields($this->rooms->key)[0];
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $options = [
            'number' => ['decimals' => 2], 'currency' => ['code' => 'USD', 'minor_digits' => 2], 'time' => ['seconds' => true],
            'choice' => ['choices' => ['Appliance', 'Tool', 'Furniture']], 'rating' => ['max' => 5],
        ];
        foreach (self::ITEM_TYPES as $type) {
            $this->f[$type] = $editor->addField($this->items->key, ucfirst(str_replace('_', ' ', $type)), $type, $options[$type] ?? [], false, $type === 'number' ? 'Weight in kg' : '');
        }
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $this->f['related'] = $editor->addRelation($this->items->key, 'Goes with', $this->items->key, Cardinality::Many, Cardinality::Many, 'Used with', false, '');

        $records = new Records($this->db, $this->schema(), $this->types, $this->clock);
        $random = new IoFixture();
        $roomIds = [];
        foreach (IoFixture::ROOMS as $room) {
            $roomIds[$room] = $records->create($this->rooms->key, [$this->roomName->key => $room], InputMode::Form, self::USER_KEY);
        }
        $roomIds['Shed'] = $records->create($this->rooms->key, [$this->roomName->key => 'Shed'], InputMode::Form, self::USER_KEY);
        $this->trashedRooms[] = $roomIds['Shed'];

        $ids = [];
        foreach ($random->itemRows(self::ITEM_COUNT) as $i => $row) {
            [$name, $room, $price, $purchased, $quantity, $inUse, $notes] = $row;
            $n = $i + 1;
            $this->clock->advance(60);
            $values = [
                $this->f['name']->key => $name,
                $this->f['long_text']->key => $notes === '' ? '' : $notes . "\nSecond line",
                $this->f['number']->key => $quantity . '.' . $random->next(10) . $random->next(10),
                $this->f['currency']->key => $n % 7 === 0 ? '-' . substr($price, 1) : $price,
                $this->f['date']->key => $purchased,
                $this->f['time']->key => sprintf('%02d:%02d:%02d', $random->next(24), $random->next(60), $random->next(60)),
                $this->f['date_time']->key => $purchased . sprintf('T%02d:%02d', $random->next(24), $random->next(60)),
                $this->f['duration']->key => $random->next(3) . ':' . sprintf('%02d', $random->next(60)),
                $this->f['checkbox']->key => $inUse,
                $this->f['choice']->key => $n % 5 === 0 ? '' : ['Appliance', 'Tool', 'Furniture'][$random->next(3)],
                $this->f['rating']->key => $n % 4 === 0 ? '' : (string) ($random->next(5) + 1),
                $this->f['url']->key => $n % 3 === 0 ? '' : 'https://example.com/items/' . $n,
                $this->f['email']->key => $n % 3 === 1 ? '' : 'item' . $n . '@example.com',
                $this->f['phone']->key => $n % 2 === 0 ? '' : sprintf('555-01%02d', $n),
            ];
            $id = $records->create($this->items->key, $values, InputMode::Form, $n % 2 === 0 ? self::USER_KEY : self::OTHER_USER_KEY);
            $ids[] = $id;
            $records->setLinks($this->f['room']->key, $id, [$n % 9 === 0 ? $roomIds['Shed'] : $roomIds[$room]], self::USER_KEY);
        }
        // Links between items, from both sides, including one to an item that goes to the trash.
        for ($i = 0; $i + 3 < count($ids); $i += 4) {
            $records->setLinks($this->f['related']->key, $ids[$i], [$ids[$i + 3], $ids[$i + 1]], self::USER_KEY);
        }
        $this->trashedItems = [$ids[1], $ids[10], $ids[25]];

        // An empty record, text that needs escaping, and stored invalid UTF-8.
        $ids[] = $records->create($this->items->key, [], InputMode::Form, self::USER_KEY);
        $this->db->run('UPDATE ' . $this->items->sql() . ' SET ' . $this->column($this->f['long_text']) . ' = ? WHERE "id" = ?', [self::ESCAPES . "\nnext line", $ids[0]]);
        $this->db->run('UPDATE ' . $this->items->sql() . ' SET ' . $this->column($this->f['name']) . ' = ? WHERE "id" = ?', [self::BAD_UTF8, $ids[2]]);

        $this->db->write(function (Database $db) use ($ids): void {
            $files = [
                ['receipt.pdf', 'application/pdf', [[$ids[0], 2], [$ids[3], 1]]],
                ['photo "front".png', 'image/png', [[$ids[0], 1]]],
                ['manual.txt', 'text/plain', [[$this->trashedItems[0], 1]]],
                ['label.png', 'image/png', [[$ids[5], 1], [$this->trashedItems[1], 1]]],
            ];
            foreach ($files as $i => [$name, $type, $links]) {
                $db->run(
                    'INSERT INTO "_tiffin_attachments" ("sha256", "size", "media_type", "original_name", "created_at") VALUES (?, ?, ?, ?, ?)',
                    [hash('sha256', 'synthetic file ' . $i), 1000 + $i, $type, $name, self::START],
                );
                $attachment = $db->lastInsertId();
                foreach ($links as [$recordId, $position]) {
                    $db->run(
                        'INSERT INTO "_tiffin_attachment_links" ("field_key", "record_id", "attachment_id", "position") VALUES (?, ?, ?, ?)',
                        [$this->f['attachment']->key, $recordId, $attachment, $position],
                    );
                }
            }
        });
        foreach ($this->trashedItems as $id) {
            $this->db->run('UPDATE ' . $this->items->sql() . ' SET "_trashed_at" = ? WHERE "id" = ?', [self::START, $id]);
        }
        foreach ($this->trashedRooms as $id) {
            $this->db->run('UPDATE ' . $this->rooms->sql() . ' SET "_trashed_at" = ? WHERE "id" = ?', [self::START, $id]);
        }
        $this->clock->advance(3600);
    }

    private function column(FieldDef $field): string
    {
        return Identifier::column($field->key);
    }

    /** @return array<string, mixed> the decoded document, objects as arrays (member order kept) */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
