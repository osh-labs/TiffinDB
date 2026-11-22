<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * List view, filter, and search results (docs/design/m2/list-and-search.md
 * sections 2 to 4; docs/specs/M2-screens.md section 5.3 for the sort
 * options). Owned by M2-WP6; the collection view texts (`lists.collection.*`)
 * by M4-WP7.
 */

declare(strict_types=1);

return [
    // List view (section 2).
    'lists.page_title' => '{table}, {project}',
    'lists.lead' => '{count|# record|# records}, sorted by {sort}',
    'lists.lead_matching' => '{count|# record matches|# records match} out of {total}, sorted by {sort}',
    'lists.add' => 'Add {item}',
    'lists.find.label' => 'Find in {table}',
    'lists.find.placeholder' => 'Type part of a name or other text',
    'lists.sort.label' => 'Sort by',
    'lists.apply' => 'Apply',
    'lists.filter.open' => 'Filter',
    'lists.filter.active' => 'Showing only records where {condition}.',
    'lists.filter.remove' => 'Remove the filter',
    'lists.filter.invalid' => 'The filter could not be used, so every record is shown.',
    'lists.cond.is' => '{field} is {value}',
    'lists.cond.empty' => '{field} is empty',
    'lists.cond.ticked' => '{field} is ticked',
    'lists.cond.not_ticked' => '{field} is not ticked',
    'lists.cond.links_to' => '{field} is {title}',
    'lists.cond.between' => '{field} is from {from} to {to}',
    'lists.cond.from' => '{field} is on or after {from}',
    'lists.cond.to' => '{field} is on or before {to}',
    'lists.caption' => '{table}, {from} to {to} of {total} shown',
    'lists.col.sorted' => '{field} ({direction})',
    'lists.col.record' => '{Item}',
    'lists.untitled' => '{Item} {id}',

    // Empty states (section 2.1).
    'lists.empty.title' => 'No records yet',
    'lists.empty.body' => 'Select Add {item} to add the first one.',
    'lists.nofields.title' => 'This table has no fields yet',
    'lists.nofields.admin' => 'Add fields to say what each record holds.',
    'lists.nofields.link' => 'Add a field',
    'lists.nofields.member' => 'An Admin needs to add fields before records can be added.',
    'lists.none.title' => 'Nothing matches',
    'lists.none.search' => 'No records match “{q}”.',
    'lists.none.filter' => 'No records match the filter.',
    'lists.none.both' => 'No records match “{q}” with this filter.',
    'lists.none.clear' => 'Show every record',

    // Collection view (docs/design/m4/collections.md section 2; M4-WP7).
    'lists.collection.lead' => '{count|# record|# records} in {table} {count|matches|match}. Sorted by {sort}.',
    'lists.collection.edit' => 'Edit collection',
    'lists.collection.broken' => '{count|# rule of this collection no longer works and is ignored.|# rules of this collection no longer work and are ignored.} Edit the collection to fix or remove {count|it|them}.',
    'lists.collection.empty' => 'No {items} match this collection yet. It updates by itself as records change.',

    // Filter (section 3).
    'lists.filter.title' => 'Filter {table}',
    'lists.filter.crumb' => 'Filter',
    'lists.filter.lead' => 'Show only the records that match one condition.',
    'lists.filter.none' => 'Filters work on choice, checkbox, link, date, and date-and-time fields. This table has none yet.',
    'lists.back' => 'Back to the list',
    'lists.filter.field' => 'Field',
    'lists.filter.field_option' => '{field} ({type})',
    'lists.filter.next' => 'Next',
    'lists.filter.where' => 'Show records where {field}',
    'lists.filter.op.is' => 'is',
    'lists.filter.value' => 'Choice',
    'lists.filter.op.empty' => 'is empty',
    'lists.filter.op.ticked' => 'is ticked',
    'lists.filter.op.not_ticked' => 'is not ticked',
    'lists.filter.op.links_to' => 'links to',
    'lists.filter.record' => 'Record',
    'lists.filter.record_limit' => 'Only the first 500 are listed.',
    'lists.filter.op.no_links' => 'has no link',
    'lists.filter.op.between' => 'is in this range',
    'lists.filter.from' => 'From',
    'lists.filter.to' => 'To',
    'lists.filter.range_help' => 'Leave one empty for an open range.',
    'lists.filter.submit' => 'Show matching records',
    'lists.filter.other' => 'Choose another field',

    // Sort options (docs/specs/M2-screens.md section 5.3).
    'sort.text.asc' => '{field}, A to Z',
    'sort.text.desc' => '{field}, Z to A',
    'sort.text.asc_short' => 'A to Z',
    'sort.text.desc_short' => 'Z to A',
    'sort.number.asc' => '{field}, lowest first',
    'sort.number.desc' => '{field}, highest first',
    'sort.number.asc_short' => 'lowest first',
    'sort.number.desc_short' => 'highest first',
    'sort.duration.asc' => '{field}, shortest first',
    'sort.duration.desc' => '{field}, longest first',
    'sort.duration.asc_short' => 'shortest first',
    'sort.duration.desc_short' => 'longest first',
    'sort.date.asc' => '{field}, oldest first',
    'sort.date.desc' => '{field}, newest first',
    'sort.date.asc_short' => 'oldest first',
    'sort.date.desc_short' => 'newest first',
    'sort.time.asc' => '{field}, earliest first',
    'sort.time.desc' => '{field}, latest first',
    'sort.time.asc_short' => 'earliest first',
    'sort.time.desc_short' => 'latest first',
    'sort.checkbox.asc' => '{field}, not ticked first',
    'sort.checkbox.desc' => '{field}, ticked first',
    'sort.checkbox.asc_short' => 'not ticked first',
    'sort.checkbox.desc_short' => 'ticked first',
    'sort.choice.asc' => '{field}, in list order',
    'sort.choice.desc' => '{field}, in reverse list order',
    'sort.choice.asc_short' => 'list order',
    'sort.choice.desc_short' => 'reverse list order',
    'sort.added.desc' => 'Newest added first',
    'sort.added.asc' => 'Oldest added first',
    'sort.changed.desc' => 'Recently changed first',
    'sort.added.desc_short' => 'newest added first',
    'sort.added.asc_short' => 'oldest added first',
    'sort.changed.desc_short' => 'recently changed first',

    // Search results (section 4).
    'search.page_title' => 'Search results, {project}',
    'search.title' => 'Search results',
    'search.lead' => '{count|# record matches|# records match} “{q}”',
    'search.lead_empty' => 'Type a word in the search box at the top of the page to look through every table in {project}.',
    'search.group' => '{table}: {count|# match|# matches}',
    'search.match' => '{field}: {excerpt}',
    'search.more' => 'Show all {count} in {table}',
    'search.none.title' => 'Nothing found',
    'search.none.body' => 'No records in {project} match “{q}”. Check the spelling, or try a shorter word.',
];
