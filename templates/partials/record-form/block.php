<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One node of a bound record form, dispatched on its kind (M5 spec part 2
 * section 6.1; the M4 dispatch, moved here from the section partial and
 * the record page): a section through record-form/section, a field slot
 * through record-form/field, anything else through record-form/static.
 *
 * Data: `node`, one node as RecordController::nodes() builds it.
 */

declare(strict_types=1);

?>
<?php if ($data['node']['kind'] === 'section') : ?>
<?= $this->partial('record-form/section', ['node' => $data['node']]) ?>
<?php elseif ($data['node']['kind'] === 'field') : ?>
<?= $this->partial('record-form/field', ['node' => $data['node']]) ?>
<?php else : ?>
<?= $this->partial('record-form/static', ['node' => $data['node']]) ?>
<?php endif; ?>
