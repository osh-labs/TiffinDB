<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;

/**
 * Live titles of a link target table (M3 spec section 6.6 step 3.3), keyed
 * by `strtolower(Text::trim(title text))`; the first id wins on duplicates
 * (counted). One query per target table, without the picker's 500 limit.
 */
final class Titles
{
    /** @param array<string, int> $ids folded title => record id */
    private function __construct(public array $ids, public readonly int $duplicates)
    {
    }

    public static function load(Database $db, Schema $schema, LinkTarget $target): self
    {
        $table = $schema->table($target->tableKey);
        $rows = $db->all(
            'SELECT "id", ' . Identifier::column($target->titleField->key) . ' AS "title" FROM ' . $table->sql() . ' WHERE "_trashed_at" IS NULL ORDER BY "id"',
        );
        $ids = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            if (!is_string($row['title'])) {
                continue;
            }
            $folded = self::fold($row['title']);
            if ($folded === '') {
                continue;
            }
            if (isset($ids[$folded])) {
                $duplicates++;

                continue;
            }
            $ids[$folded] = (int) $row['id'];
        }

        return new self($ids, $duplicates);
    }

    public static function fold(string $title): string
    {
        return strtolower(Text::trim($title));
    }

    /**
     * The title as the target's title field would store it (lenient parse
     * with its options), or null when the field refuses it: too long, not a
     * choice label, not an address for url and email, or empty.
     */
    public static function parse(FieldTypes $types, LinkTarget $target, string $title): ?string
    {
        $field = $target->titleField;
        $checked = $types->get($field->type)->parse($title, $field->options, true);
        if (!$checked->isOk()) {
            return null;
        }
        $value = $checked->value();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
