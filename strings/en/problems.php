<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Message keys produced by the core (M1 spec part 1 section 12), with
 * their English text exactly as specified there. {name} placeholders are
 * the problem's parameters, except {wait}, which Format::problem() builds
 * from the `seconds` parameter.
 */

declare(strict_types=1);

return [
    'problem.name.empty' => 'Enter a name.',
    'problem.name.too_long' => 'Use at most {max} characters.',
    'problem.text.control' => 'Remove the special characters, such as tabs or invisible characters.',
    'problem.text.line_break' => 'Keep this to one line.',
    'problem.text.too_long' => 'Use at most {max} characters.',
    'problem.table_name.taken' => 'Another table in this project already has this name.',
    'problem.field_name.taken' => 'Another field in this table already has this name.',
    'problem.project_name.taken' => 'Another project already has this name.',
    'problem.type.unknown' => 'Choose a type.',
    'problem.type.not_convertible' => 'A field of this type cannot be changed to that type.',
    'problem.table.too_many_fields' => 'A table can have at most {max} fields.',
    'problem.table.too_large_to_convert' => 'This table has more than {max} records, which is too many to change the type here.',
    'problem.title_field.invalid' => 'Choose a field that holds text, a number, or a date.',
    'problem.relation.target_missing' => 'Choose the table to link to.',
    'problem.relation.reverse_name_taken' => 'The table {table} already has a field with this name. Choose another name.',
    'problem.relation.cardinality_conflict' => '{count} records already link to more than one. Remove the extra links first, or keep "several".',
    'problem.options.range' => 'Choose a number from {min} to {max}.',
    'problem.options.invalid' => 'Choose one of the options shown.',
    'problem.options.min_max' => 'The smallest value must be less than the largest.',
    'problem.options.currency_code' => 'Use a three-letter currency code, for example USD, EUR, or GBP.',
    'problem.options.choices_empty' => 'Add at least one choice, one per line.',
    'problem.options.choices_too_many' => 'Use at most {max} choices.',
    'problem.options.choice_too_long' => 'Each choice can have at most {max} characters.',
    'problem.options.choices_duplicate' => 'The choice "{label}" is listed twice.',
    'problem.options.choices_unconvertible' => 'The existing values cannot be turned into a list of choices. Make it a text field instead, or tidy the values first.',
    'problem.options.minor_digits_fixed' => 'The number of digits after the point cannot be changed once the field exists.',
    'problem.options.start_fixed' => 'The starting number cannot be changed once the field exists.',
    'problem.number.invalid' => 'Enter a number, for example 12 or 3.5.',
    'problem.number.whole' => 'Enter a whole number, without a point.',
    'problem.number.decimals' => 'Use at most {decimals} digits after the point.',
    'problem.number.too_small' => 'Enter {min} or more.',
    'problem.number.too_large' => 'Enter {max} or less.',
    'problem.number.range' => 'That number is too large to store.',
    'problem.currency.invalid' => 'Enter an amount, for example 12.50.',
    'problem.currency.decimals' => 'Use at most {digits} digits after the point.',
    'problem.date.invalid' => 'Enter a date as year, month, day, for example 2026-03-14.',
    'problem.time.invalid' => 'Enter a time as hours and minutes, for example 14:30.',
    'problem.date_time.invalid' => 'Enter a date and a time, for example 2026-03-14 14:30.',
    'problem.duration.invalid' => 'Enter hours and minutes, for example 1:30.',
    'problem.checkbox.invalid' => 'Tick the box or leave it empty.',
    'problem.choice.invalid' => 'Pick one of the listed choices.',
    'problem.rating.invalid' => 'Choose a rating from 1 to {max}.',
    'problem.url.invalid' => 'Enter a web address that starts with https:// or http://.',
    'problem.email.invalid' => 'Enter an email address, for example name@example.com.',
    'problem.phone.invalid' => 'Enter a phone number using digits, spaces, and + ( ) - . only.',
    'problem.value.unsupported' => 'This field cannot be changed here.',
    'problem.auto_number.read_only' => 'TiffinDB sets this number. It cannot be typed in.',
    'problem.username.invalid' => 'Use 3 to 32 letters, numbers, dots, dashes, or underscores, with no spaces.',
    'problem.username.taken' => 'Someone already uses this username.',
    'problem.password.too_short' => 'Use at least {min} characters.',
    'problem.password.too_long' => 'That password is too long. Use a shorter one.',
    'problem.password.same_as_username' => 'Do not use the username as the password.',
    'problem.password.current_wrong' => 'That is not your current password.',
    'problem.people.last_admin' => 'There must always be at least one Admin who can sign in. Make someone else an Admin first.',
    'problem.people.self_deactivate' => 'You cannot stop yourself from signing in.',
    'problem.people.admin_has_all' => 'Admins can use every project, so there is nothing to choose.',
    'problem.setup_code.wrong' => 'That setup code is not right. Open the file again and copy the code exactly.',
    'problem.throttled' => 'Too many tries. Wait {wait}, then try again.',
];
