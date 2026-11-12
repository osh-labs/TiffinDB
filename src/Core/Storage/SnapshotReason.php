<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/** Why a snapshot was taken; the value is part of its file name (`docs/formats/tiffin.md` section 10). */
enum SnapshotReason: string
{
    case FormatUpgrade = 'format-upgrade';
    case TableDelete = 'table-delete';
    case FieldDelete = 'field-delete';
    case FieldTypeChange = 'field-type-change';
    case FieldOptions = 'field-options';
    case EmptyTrash = 'empty-trash';
    case Manual = 'manual';

    /** Automatic snapshots are pruned to the newest Snapshots::KEEP_AUTOMATIC; manual ones never. */
    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }
}
