<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the attachment pages (docs/design/m4/files.md): upload,
 * replace, the file page, and the remove confirmation. {name} and {old}
 * are file names, {title} a record's title, {field} the field's label,
 * {item} the table's item name, all plain text from the caller. The
 * notices are in records.php.
 */

declare(strict_types=1);

return [
    'files.upload.title' => 'Add a file to {title}',
    'files.upload.crumb' => 'Add a file',
    'files.upload.lead' => 'The file is added to {field}.',
    'files.upload.label' => 'File',
    'files.upload.help' => 'Any kind of file, up to {max}. Pictures in PNG, JPEG, GIF, or WebP are shown on the record; other files are kept for download.',
    'files.upload.help_images' => 'A picture in PNG, JPEG, GIF, or WebP, up to {max}.',
    'files.upload.submit' => 'Add the file',
    'files.upload.note' => '{count|This field holds # file.|This field holds # files.} At most {max_files} per {item}.',

    'files.replace.title' => 'Replace {name}',
    'files.replace.crumb' => 'Replace a file',
    'files.replace.lead' => 'The new file takes the place of {name} in {field}. {name} is deleted from TiffinDB when the new file is saved.',
    'files.replace.submit' => 'Replace the file',

    'files.show.lead' => 'In {field} of {title}.',
    'files.show.not_shown' => 'TiffinDB does not show this kind of file. Download it to open it on your device.',
    'files.show.facts' => 'About this file',
    'files.show.type' => 'Kind',
    'files.show.size' => 'Size',
    'files.show.added' => 'Added',
    'files.show.added_by' => '{date} by {name}',
    'files.show.download' => 'Download',
    'files.show.replace' => 'Replace',
    'files.show.remove' => 'Remove',
    'files.show.back' => 'Back to {title}',

    'files.remove.title' => 'Remove {name}?',
    'files.remove.crumb' => 'Remove a file',
    'files.remove.body' => '{name} will be removed from {title} and deleted from TiffinDB. This cannot be undone.',
    'files.remove.submit' => 'Remove the file',
];
