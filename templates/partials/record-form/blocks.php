<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The nodes of one container of a bound record form: the form's root or
 * a section (M5 spec part 2 section 6.1; docs/design/m5/components.md
 * section 1.1). In column mode (`columns` true: a node has a span of 1 to
 * 12) the nodes sit in `div.form-area > div.form-cols`, one
 * record-form/cell per node, in source order; otherwise each node is
 * written through record-form/block, as in M2 to M4.
 *
 * Data: `nodes` (list of nodes as RecordController::nodes() builds them),
 * `columns` (bool).
 */

declare(strict_types=1);

?>
<?php if ($data['columns'] === true) : ?>
<div class="form-area">
<div class="form-cols">
<?php foreach ($data['nodes'] as $node) : ?>
<?= $this->partial('record-form/cell', ['node' => $node]) ?>
<?php endforeach; ?>
</div>
</div>
<?php else : ?>
<?php foreach ($data['nodes'] as $node) : ?>
<?= $this->partial('record-form/block', ['node' => $node]) ?>
<?php endforeach; ?>
<?php endif; ?>
