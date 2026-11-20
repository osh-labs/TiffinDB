<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use TiffinDB\Core\Query\Attachments;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\Storage\AttachmentStore;
use TiffinDB\Core\Storage\StoredUpload;
use TiffinDB\Tests\Core\Schema\ProjectFixture;

require_once __DIR__ . '/../schema/ProjectFixture.php';

/**
 * A project with an "Items" table holding one field of every type, a
 * "Rooms" table, a one-to-many relation from items to rooms (an item is
 * in one room), a many-to-many relation from items to rooms ("Stored
 * in"), and a self-relation on items ("Parts"). Seed given by each test
 * class. All data is synthetic.
 */
trait RecordsFixture
{
    use ProjectFixture;

    private TableDef $items;
    private TableDef $rooms;
    /** @var array<string, FieldDef> by short name */
    private array $f = [];
    /** Staged uploads made so far (each in its own staging folder). */
    private int $staged = 0;

    private function setUpRecords(string $seed): void
    {
        $this->setUpProject($seed);
        $editor = $this->editor();
        $this->items = $editor->createTable('Items', 'item', true);
        $this->rooms = $editor->createTable('Rooms', 'room', true);
        $this->f['name'] = $this->schema()->fields($this->items->key)[0];
        $this->f['room_name'] = $this->schema()->fields($this->rooms->key)[0];
        $options = ['number' => ['decimals' => 2], 'choice' => ['choices' => ['Red', 'Green', 'Blue']]];
        foreach (['text', 'long_text', 'url', 'email', 'phone', 'number', 'currency', 'rating', 'duration', 'date', 'time', 'date_time', 'checkbox', 'choice'] as $type) {
            $this->f[$type] = $editor->addField($this->items->key, 'F ' . $type, $type, $options[$type] ?? [], false, '');
        }
        $this->f['auto'] = $editor->addField($this->items->key, 'F auto', 'auto_number', [], false, '');
        $this->f['files'] = $editor->addField($this->items->key, 'F files', 'attachment', [], false, '');
        $this->f['room'] = $editor->addRelation($this->items->key, 'Room', $this->rooms->key, Cardinality::One, Cardinality::Many, 'Items in room', false, '');
        $this->f['stored'] = $editor->addRelation($this->items->key, 'Stored in', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Stores', false, '');
        $this->f['parts'] = $editor->addRelation($this->items->key, 'Parts', $this->items->key, Cardinality::Many, Cardinality::Many, 'Part of', false, '');
        $schema = $this->schema();
        foreach ($schema->fields($this->rooms->key) as $field) {
            if ($field->name === 'Items in room') {
                $this->f['room_items'] = $field;
            }
            if ($field->name === 'Stores') {
                $this->f['stores'] = $field;
            }
        }
        foreach ($schema->fields($this->items->key) as $field) {
            if ($field->name === 'Part of') {
                $this->f['part_of'] = $field;
            }
        }
    }

    private function store(): AttachmentStore
    {
        return new AttachmentStore($this->folder);
    }

    private function attachments(): Attachments
    {
        return new Attachments($this->db, $this->schema(), $this->store(), $this->clock);
    }

    /** Writes $content as a staged upload outside the project folder and inspects it. */
    private function stage(string $content): StoredUpload
    {
        $this->staged++;
        $folder = $this->temp->path('staging/uploads/job' . $this->staged);
        mkdir($folder, 0750, true);
        file_put_contents($folder . '/upload.bin', $content);

        return $this->store()->inspect($folder . '/upload.bin');
    }

    /** Adds $content to the record's attachment field (default: items "F files"); returns the attachment id. */
    private function attach(int $recordId, string $content, string $name = 'example.bin', ?FieldDef $field = null): int
    {
        $added = $this->attachments()->add(($field ?? $this->f['files'])->key, $recordId, $this->stage($content), $name, self::USER_KEY, null);

        return $added->attachment->id;
    }

    /** The stored file of a hash, or null when no regular file is there. */
    private function storedFile(string $sha256): ?string
    {
        return $this->store()->path($sha256);
    }

    private function records(): Records
    {
        return new Records($this->db, $this->schema(), $this->types, $this->clock);
    }

    /** @param array<string, string> $text short field name => form text */
    private function item(array $text = []): int
    {
        $values = [];
        foreach ($text as $short => $value) {
            $values[$this->f[$short]->key] = $value;
        }

        return $this->records()->create($this->items->key, $values, InputMode::Form, self::USER_KEY);
    }

    private function room(string $name): int
    {
        return $this->records()->create($this->rooms->key, [$this->f['room_name']->key => $name], InputMode::Form, self::USER_KEY);
    }

    private function relationKey(FieldDef $field): string
    {
        return (string) $field->options['relation'];
    }

    /** @return array<string, mixed> */
    private function rowOf(TableDef $table, int $id): array
    {
        $row = $this->db->one('SELECT * FROM ' . $table->sql() . ' WHERE "id" = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    private function trashRecord(TableDef $table, int $id): void
    {
        $this->db->run('UPDATE ' . $table->sql() . ' SET "_trashed_at" = ? WHERE "id" = ?', [self::START, $id]);
    }

    private function meta(string $key): ?string
    {
        $value = $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = ?', [$key]);

        return is_string($value) ? $value : null;
    }
}
