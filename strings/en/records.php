<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Strings of the record screens (docs/design/m2/record.md): the record
 * form, new record, record in the trash, edit conflict, and their notices.
 * {Item} is the table's item name with a capital first letter, {item} the
 * item name as stored.
 *
 * M4 (docs/design/m4/record-controls.md sections 4 and 6, picker.md
 * section 3): the new-record lead from a picker and the notices of links
 * and files. {title} is a record's title, {name} and {old} file names.
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
    'records.new.lead_from' => 'Fill in what you know. When you save, this {item} is linked to {title} and you go back to choosing.',
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

    'records.link_detail' => '{field} {value}',

    'notice.link_added' => '{title} is now linked.',
    'notice.link_chosen' => '{title} is now chosen.',
    'notice.link_removed' => '{title} is no longer linked.',
    'notice.link_added_new' => '{title} has been added and linked.',
    'notice.file_added' => '{name} has been added.',
    'notice.file_replaced' => '{name} has replaced {old}.',
    'notice.file_removed' => '{name} has been removed.',
];
