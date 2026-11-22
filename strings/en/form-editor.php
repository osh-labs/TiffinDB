<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the simple form editor and the "back to the standard form"
 * confirmation (docs/design/m5/editor.md and reset.md; M5 spec part 2
 * section 7), and the notices they flash.
 */

declare(strict_types=1);

return [
    'forms.editor.crumb' => 'Form',
    'forms.editor.title' => 'Form for {table}',
    'forms.editor.lead' => 'Choose which fields the record screen shows, in what order, how wide, and in which sections. Records keep all their values, whatever the form shows.',
    'forms.editor.standard' => 'This is the standard form. It follows the table\'s fields by itself until you save a change.',
    'forms.editor.edited' => 'This form has been arranged by hand. Fields added to the table later appear at the end of its first section.',
    'forms.editor.refused_title' => 'The saved form could not be read',
    'forms.editor.refused' => 'Records are shown with the standard layout meanwhile. The editor starts from the standard layout; saving replaces the saved form. The Source page shows what is wrong.',
    'forms.editor.help' => 'Leave a label empty to use the field\'s name. Widths apply where the screen is wide enough; on narrow screens every field takes the whole width.',
    'forms.editor.apply' => 'Apply changes',
    'forms.editor.rows_label' => 'Fields and sections, in reading order',
    'forms.editor.add_section' => 'Add a section',
    'forms.editor.not_on_form' => 'Not on this form',
    'forms.editor.all_on_form' => 'Every field of {table} is on this form.',
    'forms.editor.required_hidden' => 'Required. While the form does not show it, records can be saved without it.',
    'forms.editor.add' => 'Add',
    'forms.editor.add_aria' => 'Add {field} to the form',
    'forms.editor.save' => 'Save the form',
    'forms.editor.cancel' => 'Cancel',
    'forms.editor.unsaved' => 'Changes stay on this page until you save them. Leaving the page drops them.',

    'forms.reset.crumb' => 'Standard form',
    'forms.reset.title' => 'Go back to the standard form?',
    'forms.reset.already' => 'The form for {table} is already the standard form.',
    'forms.reset.back' => 'Back to the form',
    'forms.reset.body' => 'The form for {table} will be replaced by the standard form: every field in field order, in the sections TiffinDB makes. Labels, widths, hidden fields, sections, headings, and text you added will be gone. There is no undo.',
    'forms.reset.download_intro' => 'To keep a copy you can upload again later, download the current form first.',
    'forms.reset.download' => 'Download the current form',
    'forms.reset.submit' => 'Use the standard form',

    'notice.form_saved' => 'The form for {table} has been saved.',
    'notice.form_saved_unchanged' => 'Nothing had changed, so the form was not saved again.',
    'notice.form_saved_standard' => 'The form for {table} has been saved. It is the same as the standard form, so it will follow the table\'s fields again.',
    'notice.form_reset' => 'The form for {table} is the standard form again.',
    'notice.form_reset_unchanged' => 'The form for {table} was already the standard form.',
];
