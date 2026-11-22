<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the relation picker (docs/design/m4/picker.md). {items} is
 * the other table's name and {item} its item name; {field} is the field's
 * label and {title} a record's title, all plain text from the caller.
 * The notices of linking and unlinking are in records.php.
 */

declare(strict_types=1);

return [
    'links.crumb' => 'Choose {items}',
    'links.title' => '{field} for {title}',
    'links.lead_one' => 'Choose one {item}. Choosing replaces the current one.',
    'links.lead_many' => 'Link as many as you need. Each press saves at once.',

    'links.now.heading' => 'Linked now',
    'links.now.none' => 'Nothing is linked yet.',
    'links.now.trashed' => '{count|# linked record is|# linked records are} in the trash. Links to records in the trash come back when they are restored.',
    'links.now.trashed_one' => 'The linked {item} is in the trash. Restore it and unlink it here, or empty the trash, before choosing another.',
    'links.unlink' => 'Unlink',
    'links.unlink_label' => 'Unlink {title}',

    'links.find.label' => 'Find in {items}',
    'links.find.button' => 'Find',
    'links.find.clear' => 'Show all',

    'links.list.heading' => '{items} you can link',
    'links.list.heading_found' => 'Found in {items}',
    'links.list.count' => '{count|# record|# records}',
    'links.list.none' => 'No {items} left to link.',
    'links.list.none_found' => 'Nothing in {items} matches "{q}".',
    'links.taken' => 'Linked to {title}',
    'links.choose' => 'Choose',
    'links.choose_label' => 'Choose {title}',
    'links.link' => 'Link',
    'links.link_label' => 'Link {title}',

    'links.back' => 'Back to {title}',
    'links.add_new_one' => 'Add a new {item} and choose it',
    'links.add_new' => 'Add a new {item} and link it',
];
