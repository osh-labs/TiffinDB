<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One cell of a container in column mode (docs/design/m5/components.md
 * section 1): `div.cell`, with the class `span-1` to `span-12` for a
 * node whose span is that number, and no span class for `full` or no
 * span (the cell then takes the whole width). The span is never printed:
 * each class is a literal branch. The cell holds the node through
 * record-form/block.
 *
 * Data: `node`, one node as RecordController::nodes() builds it, with
 * `span` ('1' to '12', 'full', or null).
 */

declare(strict_types=1);

?>
<?php if ($data['node']['span'] === '1') : ?>
<div class="cell span-1">
<?php elseif ($data['node']['span'] === '2') : ?>
<div class="cell span-2">
<?php elseif ($data['node']['span'] === '3') : ?>
<div class="cell span-3">
<?php elseif ($data['node']['span'] === '4') : ?>
<div class="cell span-4">
<?php elseif ($data['node']['span'] === '5') : ?>
<div class="cell span-5">
<?php elseif ($data['node']['span'] === '6') : ?>
<div class="cell span-6">
<?php elseif ($data['node']['span'] === '7') : ?>
<div class="cell span-7">
<?php elseif ($data['node']['span'] === '8') : ?>
<div class="cell span-8">
<?php elseif ($data['node']['span'] === '9') : ?>
<div class="cell span-9">
<?php elseif ($data['node']['span'] === '10') : ?>
<div class="cell span-10">
<?php elseif ($data['node']['span'] === '11') : ?>
<div class="cell span-11">
<?php elseif ($data['node']['span'] === '12') : ?>
<div class="cell span-12">
<?php else : ?>
<div class="cell">
<?php endif; ?>
<?= $this->partial('record-form/block', ['node' => $data['node']]) ?>
</div>
