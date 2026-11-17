<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Field type labels, hints, and the type picker's group headings
 * (M1 spec part 2 section 5.4).
 */

declare(strict_types=1);

return [
    'type.text' => 'Text',
    'type.text.hint' => 'A short piece of text on one line, such as a name.',
    'type.long_text' => 'Long text',
    'type.long_text.hint' => 'Several lines of text, such as notes.',
    'type.number' => 'Number',
    'type.number.hint' => 'A number, such as a quantity or a measurement.',
    'type.currency' => 'Currency',
    'type.currency.hint' => 'An amount of money.',
    'type.date' => 'Date',
    'type.date.hint' => 'A day, such as a purchase date or a birthday.',
    'type.time' => 'Time',
    'type.time.hint' => 'A time of day.',
    'type.date_time' => 'Date and time',
    'type.date_time.hint' => 'A day and a time, such as an appointment.',
    'type.duration' => 'Duration',
    'type.duration.hint' => 'A length of time, such as 1 hour 30 minutes.',
    'type.checkbox' => 'Checkbox',
    'type.checkbox.hint' => 'Yes or no, shown as a box to tick.',
    'type.choice' => 'Choice',
    'type.choice.hint' => 'One option picked from a list you set.',
    'type.rating' => 'Rating',
    'type.rating.hint' => 'A score from 1 to 5, or to another top score you choose.',
    'type.url' => 'Web address',
    'type.url.hint' => 'A link to a web page.',
    'type.email' => 'Email address',
    'type.email.hint' => 'An email address.',
    'type.phone' => 'Phone number',
    'type.phone.hint' => 'A phone number.',
    'type.attachment' => 'File or image',
    'type.attachment.hint' => 'Files such as photos, receipts, or manuals.',
    'type.relation' => 'Link to another table',
    'type.relation.hint' => 'Connects records, such as an item and the room it is in.',
    'type.auto_number' => 'Auto-number',
    'type.auto_number.hint' => 'A number TiffinDB gives each new record: 1, 2, 3, and so on.',

    'type.relation.to' => 'Link to {table}',

    'type.group.words' => 'Words',
    'type.group.numbers' => 'Numbers and money',
    'type.group.time' => 'Dates and times',
    'type.group.choices' => 'Yes or no, and choices',
    'type.group.contact' => 'Contact and web',
    'type.group.links' => 'Files and links',
];
