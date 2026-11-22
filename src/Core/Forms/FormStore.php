<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Stored forms in `_tiffin_forms` (M2 spec section 5.5). Every SQL
 * statement here is a literal; every value is bound.
 *
 * Stored form text is never handed out to be printed: effective() parses
 * it with the default-deny FormParser and falls back to the generated
 * layout (ADR 0014 decision 4).
 */
final class FormStore
{
    public const GENERATED_NAME = 'Standard form';

    private readonly FormGenerator $generator;
    private readonly FormSerializer $serializer;

    public function __construct(
        private readonly Database $db,
        private readonly Keys $keys,
        private readonly Clock $clock,
        private readonly FormParser $parser,
    ) {
        $this->generator = new FormGenerator();
        $this->serializer = new FormSerializer();
    }

    /**
     * The table's default form, or null when it has none. Unavailable
     * ('not-project') when the row does not have the documented types.
     */
    public function defaultFor(string $tableKey): ?StoredForm
    {
        $row = $this->db->one(
            'SELECT "key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at"'
            . ' FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1',
            [$tableKey],
        );
        if ($row === null) {
            return null;
        }
        foreach (['key', 'table_key', 'name', 'html', 'css', 'created_at', 'updated_at'] as $column) {
            if (!is_string($row[$column])) {
                throw new Unavailable(Unavailable::NOT_PROJECT);
            }
        }
        if (!is_int($row['format']) || !in_array($row['generated'], [0, 1], true)) {
            throw new Unavailable(Unavailable::NOT_PROJECT);
        }

        return new StoredForm(
            $row['key'],
            $row['table_key'],
            $row['name'],
            $row['format'],
            $row['html'],
            $row['css'],
            true,
            $row['generated'] === 1,
            $row['created_at'],
            $row['updated_at'],
        );
    }

    /**
     * The document to show: the default form parsed, or the generated
     * layout when there is none, its format is not 1, or it is rejected.
     * Never writes. A form that is not used is logged with the table key
     * and the reason code only, never its content. Form CSS is ignored.
     */
    public function effective(Schema $schema, string $tableKey): FormDocument
    {
        $schema->table($tableKey);
        try {
            $stored = $this->defaultFor($tableKey);
        } catch (Unavailable) {
            self::log($tableKey, 'row');
            $stored = null;
        }
        if ($stored === null) {
            return $this->generator->generate($schema, $tableKey);
        }
        if ($stored->format !== FormFormat::VERSION) {
            self::log($tableKey, FormRejected::FORMAT_VERSION);

            return $this->generator->generate($schema, $tableKey);
        }
        try {
            return $this->parser->parse($stored->html);
        } catch (FormRejected $rejected) {
            self::log($tableKey, $rejected->reason);

            return $this->generator->generate($schema, $tableKey);
        }
    }

    /**
     * Stores $document as the table's default form (M5 spec section 7.1);
     * must run inside the caller's write(). The text stored is the
     * canonical serialization (`docs/formats/tiffin.md` section 12.5).
     * With no default row, one is inserted; otherwise only `format`,
     * `html`, `generated`, and `updated_at` change, and only when the text,
     * the generated flag, or the format differ from the row.
     *
     * @throws \LengthException when the text is longer than FormFormat::MAX_BYTES (callers check first)
     */
    public function saveDefault(Schema $schema, string $tableKey, FormDocument $document): FormWrite
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('FormStore::saveDefault must run inside a write transaction.');
        }
        $schema->table($tableKey);
        $html = $this->serializer->serialize($document);
        if (strlen($html) > FormFormat::MAX_BYTES) {
            throw new \LengthException('The form text is longer than form format 1 allows.');
        }
        $generated = $html === $this->serializer->serialize($this->generator->generate($schema, $tableKey));
        $stored = $this->defaultFor($tableKey);
        $now = Timestamp::format($this->clock->now());
        if ($stored === null) {
            $key = $this->newKey();
            $this->db->run(
                'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at")'
                . ' VALUES (?, ?, ?, 1, ?, \'\', 1, ?, ?, ?)',
                [$key, $tableKey, self::GENERATED_NAME, $html, $generated ? 1 : 0, $now, $now],
            );

            return new FormWrite($key, $html, $generated, true);
        }
        if ($stored->html === $html && $stored->generated === $generated && $stored->format === FormFormat::VERSION) {
            return new FormWrite($stored->key, $html, $generated, false);
        }
        $this->db->run(
            'UPDATE "_tiffin_forms" SET "format" = 1, "html" = ?, "generated" = ?, "updated_at" = ? WHERE "key" = ?',
            [$html, $generated ? 1 : 0, $now, $stored->key],
        );

        return new FormWrite($stored->key, $html, $generated, true);
    }

    /** Stores the generated layout as the table's default form; as saveDefault. */
    public function resetDefault(Schema $schema, string $tableKey): FormWrite
    {
        $schema->table($tableKey);

        return $this->saveDefault($schema, $tableKey, $this->generator->generate($schema, $tableKey));
    }

    /** The save token of a stored form (ADR 0020 decision 12): SHA-256 of its text, or of '' when there is none. */
    public static function baseOf(?StoredForm $stored): string
    {
        return hash('sha256', $stored === null ? '' : $stored->html);
    }

    /**
     * Brings the default forms of the given tables in line with the schema
     * (M2 spec section 5.5, M5 spec section 7.1); must run inside the
     * caller's write(). Keys that are not tables of $schema are skipped. A
     * default form whose format is not 1 is left as it is. An edited form
     * loses the slots of keys that are not fields of its table and gains a
     * slot only for each field in $addedFieldKeys that no slot names, so a
     * field a person hid stays hidden.
     *
     * @param list<string> $tableKeys
     * @param list<string> $addedFieldKeys fields the change has just added
     */
    public function regenerate(Schema $schema, array $tableKeys, array $addedFieldKeys = []): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('FormStore::regenerate must run inside a write transaction.');
        }
        $tables = [];
        foreach ($schema->tables() as $table) {
            $tables[$table->key] = true;
        }
        $added = [];
        foreach ($addedFieldKeys as $key) {
            if (is_string($key)) {
                $added[$key] = true;
            }
        }
        foreach (array_unique($tableKeys) as $tableKey) {
            if (is_string($tableKey) && isset($tables[$tableKey])) {
                $this->regenerateOne($schema, $tableKey, $added);
            }
        }
    }

    /** @param array<string, true> $added */
    private function regenerateOne(Schema $schema, string $tableKey, array $added): void
    {
        $stored = $this->defaultFor($tableKey);
        $now = Timestamp::format($this->clock->now());
        if ($stored === null) {
            $html = $this->serializer->serialize($this->generator->generate($schema, $tableKey));
            $this->db->run(
                'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at")'
                . ' VALUES (?, ?, ?, 1, ?, \'\', 1, 1, ?, ?)',
                [$this->newKey(), $tableKey, self::GENERATED_NAME, $html, $now, $now],
            );

            return;
        }
        if ($stored->format !== FormFormat::VERSION) {
            return;
        }
        if ($stored->generated) {
            $html = $this->serializer->serialize($this->generator->generate($schema, $tableKey));
        } else {
            try {
                $document = $this->parser->parse($stored->html);
            } catch (FormRejected) {
                return;
            }
            $adjusted = self::adjust($document, $schema, $tableKey, $added);
            if ($this->serializer->serialize($adjusted) === $this->serializer->serialize($document)) {
                return;
            }
            $html = $this->serializer->serialize($adjusted);
        }
        if ($html !== $stored->html) {
            $this->db->run('UPDATE "_tiffin_forms" SET "html" = ?, "updated_at" = ? WHERE "key" = ?', [$html, $now, $stored->key]);
        }
    }

    /**
     * An edited form brought in line with the table's fields: slots for
     * keys that are not fields of the table removed; a slot appended for
     * each field in $added that no slot names, in field order, at the end
     * of the first section (or of the root when there is none). Nothing
     * else changes.
     *
     * @param array<string, true> $added
     */
    private static function adjust(FormDocument $document, Schema $schema, string $tableKey, array $added): FormDocument
    {
        $fieldKeys = [];
        foreach ($schema->fields($tableKey) as $field) {
            $fieldKeys[$field->key] = true;
        }
        $blocks = [];
        foreach ($document->blocks as $block) {
            if ($block instanceof FieldSlot && !isset($fieldKeys[$block->fieldKey])) {
                continue;
            }
            if ($block instanceof Section) {
                $children = [];
                foreach ($block->children as $child) {
                    if (!$child instanceof FieldSlot || isset($fieldKeys[$child->fieldKey])) {
                        $children[] = $child;
                    }
                }
                $block = new Section($block->role, $block->legend, $children, $block->placement);
            }
            $blocks[] = $block;
        }

        $named = array_flip($document->fieldKeys());
        $slots = [];
        foreach (array_keys($fieldKeys) as $key) {
            if (!isset($named[$key]) && isset($added[$key])) {
                $slots[] = new FieldSlot((string) $key);
            }
        }
        if ($slots === []) {
            return new FormDocument($blocks);
        }
        foreach ($blocks as $i => $block) {
            if ($block instanceof Section) {
                $blocks[$i] = new Section($block->role, $block->legend, [...$block->children, ...$slots], $block->placement);

                return new FormDocument($blocks);
            }
        }

        return new FormDocument([...$blocks, ...$slots]);
    }

    /**
     * A key not used by any table, field, relation, form, or collection of this project
     * (one key scope, `docs/formats/tiffin.md` section 4.6), nor by any SQL
     * object; the same check as SchemaEditor's.
     */
    private function newKey(): string
    {
        return $this->keys->generateUnique(function (string $key): bool {
            $used = $this->db->value(
                'SELECT 1 FROM "_tiffin_tables" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_fields" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_relations" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_forms" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_collections" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "sqlite_master" WHERE "name" IN (?, ?)',
                [$key, $key, $key, $key, $key, Identifier::tableName($key), Identifier::joinName($key)],
            );

            return $used !== null;
        });
    }

    private static function log(string $tableKey, string $reason): void
    {
        error_log('TiffinDB: stored form not used; reason=' . $reason . '; table_key=' . $tableKey);
    }
}
