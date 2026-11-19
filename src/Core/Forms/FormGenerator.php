<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Schema;

/**
 * The generated layout of a table (`docs/formats/tiffin.md` section 12.3,
 * M2 spec section 5.4, ADR 0014 decision 3), from its fields in position
 * order:
 *
 * 1. a `details` section (legend `Details`) with every field except
 *    attachment fields and relation fields that may link to several
 *    records; long text and rating fields span the full width. Written
 *    even when empty;
 * 2. a `files` section (legend `Files`) when the table has attachment fields;
 * 3. one `links` section per many-link relation field, legend = its name.
 *
 * The legends are English data, like the `Name` field of a new table (P45).
 */
final class FormGenerator
{
    public const DETAILS = 'Details';
    public const FILES = 'Files';

    private const FULL_WIDTH = ['long_text', 'rating'];

    public function generate(Schema $schema, string $tableKey): FormDocument
    {
        $details = [];
        $files = [];
        $links = [];
        foreach ($schema->fields($tableKey) as $field) {
            if ($field->type === 'attachment') {
                $files[] = new FieldSlot($field->key);

                continue;
            }
            $relation = $schema->relationOf($field);
            if ($relation !== null && $relation->maxFor($field->key) === Cardinality::Many) {
                $links[] = new Section('links', $field->name, [new FieldSlot($field->key)]);

                continue;
            }
            $full = in_array($field->type, self::FULL_WIDTH, true);
            $details[] = new FieldSlot($field->key, null, $full ? new Placement(null, null, 'full') : null);
        }

        $blocks = [new Section('details', self::DETAILS, $details)];
        if ($files !== []) {
            $blocks[] = new Section('files', self::FILES, $files);
        }

        return new FormDocument([...$blocks, ...$links]);
    }
}
