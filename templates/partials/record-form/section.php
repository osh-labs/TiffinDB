<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A section of a bound record form (docs/design/m2/record.md section 2.1):
 * a card fieldset, laid out as the Details grid when its role is
 * `details`, with the stored legend (none when the stored section has
 * none), then its children through the record-form partials.
 *
 * Data: `node` = {kind: 'section', legend: plain text or null, grid: bool,
 * spanFull: bool, children: list of nodes}.
 */

declare(strict_types=1);

?>
<?php if ($data['node']['grid'] === true && $data['node']['spanFull'] === true) : ?>
<fieldset class="fieldset-card record-section record-grid span-full">
<?php elseif ($data['node']['grid'] === true) : ?>
<fieldset class="fieldset-card record-section record-grid">
<?php elseif ($data['node']['spanFull'] === true) : ?>
<fieldset class="fieldset-card record-section span-full">
<?php else : ?>
<fieldset class="fieldset-card record-section">
<?php endif; ?>
<?php if (is_string($data['node']['legend'])) : ?>
<legend class="legend"><?= $this->e($data['node']['legend']) ?></legend>
<?php endif; ?>
<?php foreach ($data['node']['children'] as $child) : ?>
<?php if ($child['kind'] === 'section') : ?>
<?= $this->partial('record-form/section', ['node' => $child]) ?>
<?php elseif ($child['kind'] === 'field') : ?>
<?= $this->partial('record-form/field', ['node' => $child]) ?>
<?php else : ?>
<?= $this->partial('record-form/static', ['node' => $child]) ?>
<?php endif; ?>
<?php endforeach; ?>
</fieldset>
