<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the form source view and the form file upload
 * (docs/design/m5/source.md; M5 spec part 2 section 7). The form tools,
 * the report, and the problem texts are in forms.php and problems.php.
 */

declare(strict_types=1);

return [
    'forms.source.crumb' => 'Source',
    'forms.source.title' => 'Form source for {table}',
    'forms.source.lead' => 'The form as text, in TiffinDB\'s form format (described in the format documentation, section 12). Check finds problems; Save uses the text. TiffinDB keeps its own tidy copy of what you save: comments and spacing are not kept.',
    'forms.source.generated' => 'This is the standard form, made from the table\'s fields. It is stored the first time you save.',
    'forms.source.refused_title' => 'The saved text has a problem',
    'forms.source.refused' => 'Records use the standard layout until it is fixed. Fix the text below and save.',
    'forms.source.label' => 'Form source',
    'forms.source.help' => 'Up to 256 KB. Element and attribute names are lower case. Links must start with http:// or https://.',
    'forms.source.save' => 'Save',
    'forms.source.check' => 'Check',
    'forms.source.cancel' => 'Cancel',
    'forms.source.checked' => 'No problems found.',
    'forms.source.at_line' => 'Line {line}: {message}',
    'forms.source.near_line' => 'Near line {line}: {message}',
    'forms.source.no_line' => '{message}',

    'forms.upload.crumb' => 'Upload',
    'forms.upload.title' => 'Upload a form file for {table}',
    'forms.upload.lead' => 'If TiffinDB can read the file, it replaces the form for {table} at once. Download the current form first if you may want it back.',
    'forms.upload.file' => 'Form file',
    'forms.upload.help' => 'One file of at most 256 KB, in TiffinDB\'s form format, saved as UTF-8.',
    'forms.upload.submit' => 'Upload and use it',
    'forms.upload.nothing_changed' => 'Nothing was changed.',

    'notice.form_source_saved' => 'The form for {table} has been saved.',
    'notice.form_source_rewritten' => 'The form for {table} has been saved. TiffinDB rewrote the text in its own layout: comments and spacing were not kept.',
    'notice.form_source_unchanged' => 'Nothing had changed, so the form was not saved again.',
    'notice.form_uploaded' => 'The file is now the form for {table}.',
    'notice.form_uploaded_rewritten' => 'The file is now the form for {table}. TiffinDB rewrote it in its own layout: comments and spacing were not kept.',
];
