<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the snapshots screen and the restore confirmation
 * (docs/design/m7/snapshots.md), their notices, and the reason words of
 * every snapshot reason (`snapshots.reason.<reason>`, built at run time).
 */

declare(strict_types=1);

return [
    'snapshots.title' => 'Snapshots',
    'snapshots.crumb' => 'Snapshots',
    'snapshots.lead' => 'A snapshot is a saved copy of this project\'s records, tables, forms, and collections. TiffinDB saves one before every risky change and keeps the 20 newest of those. Snapshots you take yourself are kept until someone removes them from the data folder.',
    'snapshots.files_note' => 'Snapshots do not hold the stored files. Files that a snapshot names but that were removed since will be missing after a restore.',
    'snapshots.take' => 'Take a snapshot now',
    'snapshots.empty' => 'No snapshots yet.',
    'snapshots.empty_body' => 'TiffinDB saves one before the first risky change. Select Take a snapshot now to save one yourself.',
    'snapshots.table.caption' => 'Snapshots, newest first',
    'snapshots.col.when' => 'Saved',
    'snapshots.col.reason' => 'Why',
    'snapshots.col.size' => 'Size',
    'snapshots.col.action' => 'Action',
    'snapshots.restore_link' => 'Restore…',
    'snapshots.restore_label' => 'Restore the snapshot saved {when}',
    'snapshots.no_lock' => 'This server cannot lock files, so restoring in TiffinDB is turned off here. The host documentation describes restoring by hand.',

    'snapshots.reason.format-upgrade' => 'Before an upgrade of TiffinDB',
    'snapshots.reason.table-delete' => 'Before a table was deleted',
    'snapshots.reason.field-delete' => 'Before a field was deleted',
    'snapshots.reason.field-type-change' => 'Before a field\'s type was changed',
    'snapshots.reason.field-options' => 'Before a field\'s options were changed',
    'snapshots.reason.empty-trash' => 'Before the trash was emptied',
    'snapshots.reason.import' => 'Before rows were imported',
    'snapshots.reason.manual' => 'Taken by an Admin',
    'snapshots.reason.pre-restore' => 'Before a restore',

    'snapshots.restore.title' => 'Restore this snapshot?',
    'snapshots.restore.crumb' => 'Restore',
    'snapshots.restore.saved' => 'Saved',
    'snapshots.restore.reason' => 'Why',
    'snapshots.restore.records' => 'Records',
    'snapshots.restore.records_value' => '{then|# record|# records} in the snapshot, {now|# record|# records} now',
    'snapshots.restore.body' => '{project} will go back to how it was when this snapshot was saved. Everything changed since then will be undone: records, tables and fields, forms, collections, and the trash. TiffinDB first saves a snapshot of the project as it is now, so you can come back to it.',
    'snapshots.restore.files_removed' => '{count|# stored file was|# stored files were} added after this snapshot and will be deleted.',
    'snapshots.restore.files_missing' => '{count|# file this snapshot names is|# files this snapshot names are} no longer stored and will show as missing.',
    'snapshots.restore.older' => 'This snapshot was saved by an earlier version of TiffinDB. It will be brought up to date after the restore.',
    'snapshots.restore.others' => 'People using this project now may need to reload their page. If someone is saving a change at this moment, you will be asked to try again.',
    'snapshots.restore.submit' => 'Restore this snapshot',

    'notice.snapshot_taken' => 'A snapshot was saved: {when}.',
    'notice.snapshot_restored' => '{project} is back to how it was on {date}. A snapshot of how it was just before was saved first.',
    'notice.snapshot_restored_missing' => '{project} is back to how it was on {date}. A snapshot of how it was just before was saved first. {count|# file is|# files are} missing from that copy.',
];
