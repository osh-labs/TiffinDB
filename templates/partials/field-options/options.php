<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The options of a field type (docs/design/m1/schema-editor.md section
 * 7.3): chooses the partial for the type's kind by a literal name.
 * Partial names cannot hold underscores (View::NAME_PATTERN), and types
 * with the same inputs share a partial.
 *
 * Data: `kind` (text, number, currency, none, seconds, choice, rating,
 * attachment, auto-number, relation), `mode` (new or edit), `values`
 * (input name => text), `errors` (element id => plain text), and per
 * kind: `max` (text), `digits` (currency, edit), `start` and `next`
 * (auto-number, edit), `choices` (choice, edit: {name, value, number}),
 * `tables` ({key, name, isThis}, relation, new), `linkedTable`
 * (relation, edit), `itemName` (relation).
 */

declare(strict_types=1);

?>
<?php if ($data['kind'] === 'text') : ?>
<?= $this->partial('field-options/text', $data) ?>
<?php elseif ($data['kind'] === 'number') : ?>
<?= $this->partial('field-options/number', $data) ?>
<?php elseif ($data['kind'] === 'currency') : ?>
<?= $this->partial('field-options/currency', $data) ?>
<?php elseif ($data['kind'] === 'seconds') : ?>
<?= $this->partial('field-options/seconds', $data) ?>
<?php elseif ($data['kind'] === 'choice') : ?>
<?= $this->partial('field-options/choice', $data) ?>
<?php elseif ($data['kind'] === 'rating') : ?>
<?= $this->partial('field-options/rating', $data) ?>
<?php elseif ($data['kind'] === 'attachment') : ?>
<?= $this->partial('field-options/attachment', $data) ?>
<?php elseif ($data['kind'] === 'auto-number') : ?>
<?= $this->partial('field-options/auto-number', $data) ?>
<?php elseif ($data['kind'] === 'relation') : ?>
<?= $this->partial('field-options/relation', $data) ?>
<?php else : ?>
<?= $this->partial('field-options/none', $data) ?>
<?php endif; ?>
