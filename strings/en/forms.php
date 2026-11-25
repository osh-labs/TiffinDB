<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the shared M5 form partials and the Fields page entry
 * (docs/design/m5/components.md sections 3 to 5 and 7; M5 spec part 2
 * section 7): the form tools navigation, the editor rows and widths, and
 * the report. The editor and source pages have their own files.
 */

declare(strict_types=1);

return [
    'forms.entry' => 'Edit the form',

    'forms.tabs.label' => 'Form tools',
    'forms.tabs.arrange' => 'Arrange',
    'forms.tabs.source' => 'Source',
    'forms.tabs.upload' => 'Upload a form file',
    'forms.tabs.download' => 'Download the form',
    'forms.tabs.reset' => 'Back to the standard form',

    'forms.row.kind.section' => 'Section',
    'forms.row.kind.heading' => 'Heading',
    'forms.row.kind.paragraph' => 'Text',
    'forms.row.kind.divider' => 'Divider',
    'forms.row.kind.image' => 'Picture',
    'forms.row.kind.missing' => 'This field is no longer in the table. It shows nothing.',
    'forms.row.required' => 'Required',
    'forms.row.label' => 'Label',
    'forms.row.label_aria' => 'Label for {field}',
    'forms.row.legend' => 'Section name',
    'forms.row.legend_aria' => 'Section name, section {number}',
    'forms.row.width' => 'Width',
    'forms.row.width_aria' => 'Width of {field}',
    'forms.width.auto' => 'Automatic',
    'forms.width.full' => 'Full width',
    'forms.width.two-thirds' => 'Two thirds',
    'forms.width.half' => 'Half',
    'forms.width.one-third' => 'One third',
    'forms.width.other' => 'As in the source: {span} of 12 columns',
    'forms.row.up' => 'Move up',
    'forms.row.up_aria' => 'Move up: {name}',
    'forms.row.down' => 'Move down',
    'forms.row.down_aria' => 'Move down: {name}',
    'forms.row.hide' => 'Hide',
    'forms.row.hide_aria' => 'Hide {field}',
    'forms.row.remove' => 'Remove',
    'forms.row.remove_aria' => 'Remove {name}',
    'forms.row.ungroup' => 'Remove section',
    'forms.row.ungroup_aria' => 'Remove section {name} and keep what is in it',
    'forms.row.section_unnamed' => 'Section {number}, no name',
    'forms.row.empty_section' => 'This section is empty.',
    'forms.row.divider' => 'A dividing line',
    'forms.row.image_alt' => 'Description: {alt}',
    'forms.row.image_no_alt' => 'No description',
    'forms.row.image_hidden' => 'This picture is not shown: its file is not a picture attached to a record.',
    'forms.row.placed' => 'Placed by the source. Shown in reading order.',

    'forms.report.title' => 'What this form shows',
    'forms.report.ok' => 'Every field of {table} is on this form, and nothing needs attention.',
    'forms.report.not_shown' => '{count|# field is|# fields are} not on this form:',
    'forms.report.required' => 'Required fields that the form does not show are not required when records are saved through it.',
    'forms.report.unknown' => '{count|# field element names|# field elements name} a field that is not in {table}. They show nothing.',
    'forms.report.images' => '{count|# picture is|# pictures are} not shown, because the file is not a picture attached to a record.',
    'forms.report.empty_links' => '{count|# link has|# links have} no text, so {count|it is|they are} not shown.',
];
