<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the shared M2 components (M2 spec part 2 section 6.3): the
 * pager, value display, record form inputs, the undo notice, and currency
 * names. The sidebar and top-bar search strings are in shell.php.
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
