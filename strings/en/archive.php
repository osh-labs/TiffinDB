<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import a project archive strings (docs/design/m3/archive.md). The
 * notices are `notice.*` keys, as every flash notice is (M1 spec part 2
 * section 4.4); the change list is built by ArchiveController from the
 * archive.change.* texts and passed as one plain parameter.
 */

declare(strict_types=1);

return [
    'archive.crumb' => 'Import a project archive',
    'archive.title' => 'Import a project archive',
    'archive.lead' => 'Make a new project from an archive downloaded from TiffinDB\'s Export page. Your other projects are not changed.',
    'archive.file' => 'Archive file',
    'archive.file_help' => 'A .zip file made by TiffinDB, up to {max}.',
    'archive.name' => 'Name for the new project',
    'archive.name_help' => 'Leave empty to use the name stored in the archive.',
    'archive.import' => 'Import',
    'archive.cancel' => 'Cancel',
    'archive.note' => 'TiffinDB checks the archive before using it. The new project is shared with nobody until you share it.',
    'archive.too_large_note' => 'An archive larger than {max} cannot be uploaded. It can be restored by hand on the server; the steps are in the TiffinDB project file documentation, section 13.4.',
    'archive.checked' => 'The archive {file} has been checked.',

    'archive.change.values' => '{count|# value|# values} left empty',
    'archive.change.links' => '{count|# link|# links} dropped',
    'archive.change.forms_dropped' => '{count|# form|# forms} replaced by the standard layout',
    'archive.change.forms_extended' => '{count|# edited form|# edited forms} lost fields that no longer exist',
    'archive.change.files' => '{count|# file|# files} missing',

    'notice.archive_imported' => 'The project {name} has been made from the archive.',
    'notice.archive_imported_changes' => 'The project {name} has been made from the archive. Some things were changed to fit TiffinDB\'s rules: {list}.',
];
