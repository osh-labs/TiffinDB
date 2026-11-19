<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\Schema;

/**
 * An existing table an import column may link to: one whose title field
 * is text-like (M3 spec sections 6.3 rule 2 and 6.4).
 */
final class LinkTarget
{
    /** Title field types a link column can match titles against. */
    public const TITLE_TYPES = ['text', 'choice', 'url', 'email', 'phone'];

    public function __construct(
        public readonly string $tableKey,
        public readonly string $name,
        public readonly string $itemName,
        public readonly FieldDef $titleField,
    ) {
    }

    /** @return list<self> every table of the schema with a text-like title field, in table order */
    public static function all(Schema $schema): array
    {
        $targets = [];
        foreach ($schema->tables() as $table) {
            $target = self::of($schema, $table->key);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /** The target for one table, or null when its title field is absent or not text-like (unknown table: NotFound). */
    public static function of(Schema $schema, string $tableKey): ?self
    {
        $table = $schema->table($tableKey);
        if ($table->titleFieldKey === null) {
            return null;
        }
        $title = $schema->field($table->titleFieldKey);
        if (!in_array($title->type, self::TITLE_TYPES, true)) {
            return null;
        }

        return new self($table->key, $table->name, $table->itemName, $title);
    }
}
