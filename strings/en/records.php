<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the record screens (docs/design/m2/record.md): the record
 * form, new record, record in the trash, edit conflict, and their notices.
 * {Item} is the table's item name with a capital first letter, {item} the
 * item name as stored.
 */

declare(strict_types=1);

return [
    'records.untitled' => '{Item} {id}',
    'records.lead' => '{Item} {position} of {total}. Last saved {when}.',
    'records.lead_saved' => 'Last saved {when}.',
    'records.previous' => 'Previous',
    'records.next' => 'Next',
    'records.previous_label' => 'Previous {item}: {title}',
    'records.next_label' => 'Next {item}: {title}',
    'records.save' => 'Save',
    'records.back' => 'Back to list',
    'records.trash' => 'Move to trash',
    'records.meta.added' => 'Added {date} by {name}.',
    'records.meta.added_unknown' => 'Added {date}.',
    'records.meta.changed' => 'Last changed {date} by {name}.',
    'records.meta.changed_unknown' => 'Last changed {date}.',
    'records.meta.trash_note' => 'Records in the trash can be restored.',

    'records.new.crumb' => 'New {item}',
    'records.new.title' => 'New {item}',
    'records.new.lead' => 'Fill in what you know. You can change it later.',
    'records.create' => 'Save',

    'records.trashed.title' => 'This {item} is in the trash',
    'records.trashed.body' => '{title} was moved to the trash {date}. Restore it to see and change it again.',
    'records.restore' => 'Restore',

    'records.conflict.title' => 'This {item} was changed while you were editing it',
    'records.conflict.body' => '{name} saved a change {when}, after you opened it. What you typed has not been saved yet.',
    'records.conflict.body_unknown' => 'It was saved again {when}, after you opened it. What you typed has not been saved yet.',
    'records.conflict.caption' => 'Fields that differ',
    'records.conflict.col.field' => 'Field',
    'records.conflict.col.saved' => 'Saved version',
    'records.conflict.col.yours' => 'Your version',
    'records.conflict.empty' => '(empty)',
    'records.conflict.same' => 'The other change was to fields you did not change here, so saving keeps both.',
    'records.conflict.keep' => 'Keep the saved version',
    'records.conflict.overwrite' => 'Save my version anyway',

    'notice.record_added' => 'The {item} has been added.',
    'notice.record_saved' => 'Your changes have been saved.',
    'notice.record_trashed' => '{title} has been moved to the trash.',
    'notice.record_restored' => '{title} has been restored.',
];
