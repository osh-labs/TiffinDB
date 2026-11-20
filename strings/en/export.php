<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Export page strings (docs/design/m3/export.md section 2). Plugin names,
 * setting labels, and setting help are the plugins' own plain text and are
 * never string keys (P133).
 */

declare(strict_types=1);

return [
    'export.crumb' => 'Export',
    'export.title' => 'Export',
    'export.lead' => 'Take a copy of this project\'s records out of TiffinDB. Exports leave the project unchanged.',

    'export.archive.heading' => 'The whole project, for TiffinDB',
    'export.archive.body' => 'A project archive holds every table, record, form, and attached file, including records in the trash. Use it as a backup, or to move the project to another TiffinDB installation.',
    'export.archive.size' => 'About {size}.',
    'export.archive.download' => 'Download the project archive',

    'export.format.legend' => 'Records, for other programs',
    'export.format.scope_table' => 'one table per file',
    'export.format.scope_both' => 'one table, or every table as separate sheets',
    'export.format.scope_project' => 'the whole project in one file',
    'export.format.spreadsheet_hint' => 'Opening the file in a spreadsheet program? Choose the Excel workbook: its text is never treated as a formula.',
    'export.format.none' => 'No export format is turned on. An Admin can turn formats on under Plugins.',

    'export.scope.legend' => 'Which records',
    'export.scope.table' => 'One table:',
    'export.scope.table_label' => 'Table to export',
    'export.scope.table_option' => '{table} ({count|# record|# records})',
    'export.scope.project' => 'Every table',
    'export.scope.help' => 'Records in the trash are not included.',

    'export.options.legend' => 'Options',

    'export.download' => 'Download',
    'export.note' => 'The file is made now and downloaded straight away. It is not kept on the server.',
];
