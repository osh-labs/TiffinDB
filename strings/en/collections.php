<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Saved collections: the editor, its rule rows and tests, the preview, the
 * delete confirmation, the notices, and the reasons a stored rule no
 * longer works (docs/design/m4/collections.md; docs/specs/M4-core.md
 * section 11 for `collection.broken.*`). Owned by M4-WP7.
 */

declare(strict_types=1);

return [
    // Editor (section 3).
    'collections.new.title' => 'New collection',
    'collections.new.crumb' => 'New collection',
    'collections.crumb' => 'Collection: {name}',
    'collections.lead' => 'A collection is a saved search. It updates by itself as records change.',
    'collections.preview' => 'Preview',
    'collections.name.label' => 'Collection name',
    'collections.match.legend' => 'Show {items} that match',
    'collections.match.all' => 'All of these rules',
    'collections.match.any' => 'Any of these rules',
    'collections.add_rule' => 'Add a rule',
    'collections.sort.label' => 'Sort by',
    'collections.sort.default' => 'As the table',
    'collections.save' => 'Save collection',
    'collections.cancel' => 'Cancel',
    'collections.delete.link' => 'Delete this collection',

    // Preview (section 3 item 5).
    'collections.preview.heading_one' => '{count} {item} matches',
    'collections.preview.heading_many' => '{count} {items} match',
    'collections.preview.first' => '(first {shown} shown)',
    'collections.preview.caption' => 'Records that match, first {shown} shown',
    'collections.preview.none' => 'Nothing matches these rules yet.',
    'collections.preview.fix' => 'Fix the rules above to see what matches.',

    // Rule row (section 4).
    'collections.rule.legend' => 'Rule {n}',
    'collections.rule.field_label' => 'Field for rule {n}',
    'collections.rule.field_none' => 'Choose a field',
    'collections.rule.test_label' => 'Test for rule {n}',
    'collections.rule.value_label' => '{kind} for rule {n}',
    'collections.rule.value_none' => 'Choose one',
    'collections.rule.records_more' => 'Only the first 500 are listed.',
    'collections.rule.remove' => 'Remove',
    'collections.rule.remove_label' => 'Remove rule {n}',
    'collections.rule.broken' => 'This rule no longer works and is ignored:',
    'collections.kind.text' => 'Text',
    'collections.kind.number' => 'Number',
    'collections.kind.amount' => 'Amount',
    'collections.kind.rating' => 'Rating',
    'collections.kind.duration' => 'Length of time',
    'collections.kind.date' => 'Date',
    'collections.kind.choice' => 'Value',
    'collections.kind.record' => 'Record',

    // Tests by field type (section 4). The core names every test by its
    // operator; the same operator reads differently for some types, so
    // the editor prints `is-exactly` for a text `is`, `not-chosen` for a
    // choice `empty`, and `no-links` for a relation `empty`.
    'collections.test.contains' => 'contains',
    'collections.test.is' => 'is',
    'collections.test.is-exactly' => 'is exactly',
    'collections.test.empty' => 'is empty',
    'collections.test.not-empty' => 'is not empty',
    'collections.test.less' => 'is less than',
    'collections.test.more' => 'is more than',
    'collections.test.on' => 'is on',
    'collections.test.before' => 'is before',
    'collections.test.after' => 'is after',
    'collections.test.not-chosen' => 'is not chosen',
    'collections.test.ticked' => 'is ticked',
    'collections.test.not-ticked' => 'is not ticked',
    'collections.test.links-to' => 'links to',
    'collections.test.no-links' => 'has no links',

    // Why a stored rule no longer works (M4 core spec section 11).
    'collection.broken.field' => 'The field this rule used has been deleted.',
    'collection.broken.type' => 'The field this rule uses has changed type.',
    'collection.broken.value' => 'The value of this rule no longer fits the field.',
    'collection.broken.choice' => 'The value this rule used has been removed from the list.',
    'collection.broken.record' => 'The record this rule used is in the trash or has been deleted.',

    // Delete confirmation (section 5).
    'collections.delete.title' => 'Delete {name}?',
    'collections.delete.crumb' => 'Delete',
    'collections.delete.body' => 'The collection is deleted. Its {items} are not changed or moved to the trash. This cannot be undone.',
    'collections.delete.submit' => 'Delete the collection',

    // Notices.
    'notice.collection_saved' => 'The collection has been saved.',
    'notice.collection_deleted' => '{name} has been deleted.',
];
