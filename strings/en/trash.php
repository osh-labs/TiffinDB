<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the trash screen and the empty-the-trash confirmation
 * (docs/design/m4/trash.md), and their notices.
 */

declare(strict_types=1);

return [
    'trash.title' => 'Trash',
    'trash.crumb' => 'Trash',
    'trash.lead' => '{count|# record is|# records are} in the trash. Records stay here until an Admin empties the trash. Restoring a record brings back its links and files.',
    'trash.empty_state' => 'The trash is empty.',
    'trash.empty_body' => 'Records moved to the trash wait here until they are restored or an Admin empties the trash.',
    'trash.back' => 'Back to {project}',
    'trash.all_tables' => 'Show every table',
    'trash.section.count' => '{count|# record|# records}',
    'trash.section.more' => 'Show all {count} {items} in the trash',
    'trash.restore_all' => 'Restore all in {table}',
    'trash.restore' => 'Restore',
    'trash.restore_label' => 'Restore {title}',
    'trash.row.meta' => 'Moved to the trash {date} by {name}.',
    'trash.row.meta_unknown' => 'Moved to the trash {date}.',
    'trash.empty.note' => 'Emptying the trash deletes these records and the files only they hold, for good.',
    'trash.empty.link' => 'Empty the trash',
    'trash.empty.title' => 'Empty the trash?',
    'trash.empty.body' => '{count|# record|# records} will be deleted for good. A copy of the project is kept as a snapshot first, but files are not part of snapshots.',
    'trash.empty.table' => '{table}: {count|# record|# records}',
    'trash.empty.files' => '{files|# file|# files} that only these records hold will be deleted.',
    'trash.empty.submit' => 'Empty the trash',
    'trash.empty.changed' => 'The trash changed while you were looking. Check the new numbers.',
    'notice.trash_restored_all' => '{count|# record|# records} in {table} {count|has|have} been restored.',
    'notice.trash_emptied' => 'The trash has been emptied: {count|# record|# records} and {files|# file|# files} deleted.',
];
