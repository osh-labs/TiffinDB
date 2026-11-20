<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use TiffinDB\Core\Query\AttachmentSummary;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/AppTestCase.php';

/**
 * A small synthetic scene for the M4 core-operation tests: project
 * "Example Home" shared with the Member `member`, and
 *
 * - Rooms: Name (title), Floor (text); "Example hall" (Ground), "Example
 *   attic" (Top), "Example cellar" (no floor);
 * - Items: Name (title), Room (relation to Rooms, one link; Rooms sees
 *   "Items", many), Stored in (relation to Rooms, many both ways), Photos
 *   (attachment, images only), Files (attachment), Count (number), Done
 *   (checkbox), Kind (choice: Tool, Lamp); "Example lamp", "Example
 *   kettle", "Example chair".
 *
 * Field keys are in $f by short name, record ids in $ids by name.
 */
abstract class M4AppTestCase extends AppTestCase
{
    protected string $p;
    protected string $items;
    protected string $rooms;
    /** @var array<string, string> */
    protected array $f = [];
    /** @var array<string, int> */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $schema = $this->ops->schema();
        $this->p = $this->ops->projects()->create($this->admin, 'Example Home', 'Items')->key;
        $this->items = $schema->tables($this->admin, $this->p)[0]->table->key;
        $this->f['name'] = $schema->design($this->admin, $this->p, $this->items)->fields[0]->field->key;
        $this->rooms = $schema->createTable($this->admin, $this->p, 'Rooms', 'room', true)->key;
        $this->f['roomName'] = $schema->design($this->admin, $this->p, $this->rooms)->fields[0]->field->key;
        $this->f['floor'] = $schema->addField($this->admin, $this->p, $this->rooms, 'Floor', 'text', [], false, '')->key;
        $this->f['room'] = $schema->addRelationField($this->admin, $this->p, $this->items, 'Room', $this->rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->f['stored'] = $schema->addRelationField($this->admin, $this->p, $this->items, 'Stored in', $this->rooms, Cardinality::Many, Cardinality::Many, 'Stored items', false, '')->key;
        $add = fn (string $name, string $type, array $options = []): string => $schema->addField($this->admin, $this->p, $this->items, $name, $type, $options, false, '')->key;
        $this->f['photos'] = $add('Photos', 'attachment', ['images_only' => true]);
        $this->f['files'] = $add('Files', 'attachment');
        $this->f['count'] = $add('Count', 'number');
        $this->f['done'] = $add('Done', 'checkbox');
        $this->f['kind'] = $add('Kind', 'choice', ['choices' => ['Tool', 'Lamp']]);
        $this->ops->projects()->setSharing($this->admin, $this->p, [$this->member->userKey]);

        foreach (['Example hall' => 'Ground', 'Example attic' => 'Top', 'Example cellar' => ''] as $room => $floor) {
            $this->ids[$room] = $this->fx->addRecord($this->p, $this->rooms, [$this->f['roomName'] => $room, $this->f['floor'] => $floor]);
        }
        foreach (['Example lamp' => '3', 'Example kettle' => '7', 'Example chair' => '12'] as $item => $count) {
            $this->ids[$item] = $this->fx->addRecord($this->p, $this->items, [$this->f['name'] => $item, $this->f['count'] => $count]);
        }
    }

    /** The key of a relation field's other side, or of any field, by table and name. */
    protected function fieldKey(string $tableKey, string $name): string
    {
        foreach ($this->ops->schema()->design($this->admin, $this->p, $tableKey)->fields as $design) {
            if ($design->field->name === $name) {
                return $design->field->key;
            }
        }
        self::fail('No field ' . $name . '.');
    }

    /** Uploads $contents to an item's attachment field as the Admin; returns the new file. */
    protected function attach(string $fieldKey, string $item, string $contents, string $name = 'example.png', ?int $replace = null): AttachmentSummary
    {
        return $this->ops->attachments()->add(
            $this->admin,
            $this->p,
            $this->items,
            (string) $this->ids[$item],
            $fieldKey,
            $this->fx->upload($contents),
            $name,
            $replace === null ? null : (string) $replace,
        );
    }

    protected static function png(string $label = 'png'): string
    {
        return FileFixture::png(1, 1, $label);
    }

    /** Where the store keeps a hash, whether or not a file is there. */
    protected function storedPath(string $sha256): string
    {
        return $this->fx->projectFolder($this->p) . '/attachments/' . substr($sha256, 0, 2) . '/' . $sha256;
    }

    /** @return list<string> the job folders under staging/uploads */
    protected function uploadJobs(): array
    {
        $folder = $this->fx->data->path() . '/staging/uploads';
        if (!is_dir($folder)) {
            return [];
        }

        return array_values(array_diff((array) scandir($folder), ['.', '..']));
    }

    /** @return list<string> every stored file's hash */
    protected function storedHashes(): array
    {
        $root = $this->fx->projectFolder($this->p) . '/attachments';
        $hashes = [];
        foreach (is_dir($root) ? array_diff((array) scandir($root), ['.', '..']) : [] as $prefix) {
            foreach (array_diff((array) scandir($root . '/' . $prefix), ['.', '..']) as $name) {
                $hashes[] = (string) $name;
            }
        }
        sort($hashes);

        return $hashes;
    }

    /** Replaces the table's default form with stored HTML (generated = 0). */
    protected function setForm(string $tableKey, string $inner): void
    {
        $this->fx->projectDb($this->p)->run(
            'UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "is_default" = 1 AND "table_key" = ?',
            ['<div data-tiffin-form="1"><fieldset data-tiffin-section="details"><legend>Card</legend>' . $inner . '</fieldset></div>', $tableKey],
        );
    }
}
