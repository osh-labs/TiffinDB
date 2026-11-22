<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A section of a bound record form (docs/design/m2/record.md section 2.1;
 * docs/design/m5/components.md section 1): a card fieldset with the
 * stored legend (none when the stored section has none), then its
 * children through record-form/blocks. Column mode (`columns` true) is
 * chosen first: the children sit in the twelve-column grid, and the
 * section is not the Details grid even when its role is `details`.
 * Otherwise a `details` section is the auto-fit Details grid (P95), as in
 * M2 to M4.
 *
 * Data: `node` = {kind: 'section', legend: plain text or null, grid: bool,
 * columns: bool, spanFull: bool, span, children: list of nodes}.
 */

declare(strict_types=1);

?>
<?php if ($data['node']['columns'] === true && $data['node']['spanFull'] === true) : ?>
<fieldset class="fieldset-card record-section span-full">
<?php elseif ($data['node']['columns'] === true) : ?>
<fieldset class="fieldset-card record-section">
<?php elseif ($data['node']['grid'] === true && $data['node']['spanFull'] === true) : ?>
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
<?= $this->partial('record-form/blocks', ['nodes' => $data['node']['children'], 'columns' => $data['node']['columns']]) ?>
</fieldset>
