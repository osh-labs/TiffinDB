<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the shared M2 components (M2 spec part 2 section 6.3): the
 * pager, value display, record form inputs, the undo notice, and currency
 * names. The sidebar and top-bar search strings are in shell.php.
 *
 * M4 (spec part 2 section 7; docs/design/m4/components.md section 3): the
 * texts of the link-control, link-rows, file-rows, and leave-note partials.
 * {item} is the other table's item name, plain text from the caller.
 */

declare(strict_types=1);

return [
    'pager.label' => 'Pages of records',
    'pager.showing' => 'Showing {from} to {to} of {total}',
    'pager.previous' => 'Previous',
    'pager.next' => 'Next',

    'value.yes' => 'Yes',
    'value.no' => 'No',
    'value.none' => 'None',
    'value.rating' => '{value} of {max}',
    'value.files' => '{count|# file|# files}',
    'value.more_links' => 'and {count} more',
    'value.date_time' => '{date}, {time}',
    'value.untitled' => '{Item} {id}',
    'value.separator' => ', ',

    'input.required' => '(required)',
    'input.choice.none' => 'Not chosen',
    'input.rating.low' => '{n}, lowest',
    'input.rating.high' => '{n}, highest',
    'input.rating.none' => 'Not rated',
    'input.duration.help' => 'Hours and minutes, for example 1:30.',
    'input.duration.help_seconds' => 'Hours, minutes, and seconds, for example 1:30:00.',
    'input.currency.label' => '{label} ({currency})',
    'input.auto_number_new' => 'Given when you save',
    'input.links.none' => 'No linked records',
    'input.links.help' => 'Linked to the {table} table.',
    'input.files.none' => 'No files',
    'input.file.facts' => '({type}, {size})',

    'file.type.pdf' => 'PDF',
    'file.type.image' => 'image',
    'file.type.other' => 'file',

    'components.link.none' => 'No {item} chosen',
    'components.link.choose' => 'Choose a record',
    'components.link.choose_other' => 'Choose a different {item}',
    'components.link.after_save' => 'You can choose one once this record is saved.',
    'components.link.unlink' => 'Unlink',
    'components.link.unlink_label' => 'Unlink {title}',
    'components.link.add_first' => 'Link a record',
    'components.link.add' => 'Link another {item}',
    'components.link.unlink_help' => 'Unlinking does not delete the {item} record.',
    'components.link.after_save_many' => 'You can add links once this record is saved.',
    'components.file.open' => 'Open',
    'components.file.open_label' => 'Open {name}',
    'components.file.replace' => 'Replace',
    'components.file.replace_label' => 'Replace {name}',
    'components.file.remove' => 'Remove',
    'components.file.remove_label' => 'Remove {name}',
    'components.file.add' => 'Add a file',
    'components.file.add_image' => 'Add an image',
    'components.file.after_save' => 'You can add files once this record is saved.',
    'components.leave_note' => 'Save your changes first: the buttons here leave this page.',

    'notice.undo' => 'Undo',
    'notice.undo_label' => 'Undo: restore {title}',

    'currency.USD' => 'US dollars',
    'currency.EUR' => 'euros',
    'currency.GBP' => 'pounds sterling',
    'currency.JPY' => 'Japanese yen',
    'currency.CAD' => 'Canadian dollars',
    'currency.AUD' => 'Australian dollars',
    'currency.NZD' => 'New Zealand dollars',
    'currency.CHF' => 'Swiss francs',
    'currency.SEK' => 'Swedish kronor',
    'currency.NOK' => 'Norwegian kroner',
    'currency.DKK' => 'Danish kroner',
    'currency.INR' => 'Indian rupees',
];
