<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Change a field (docs/design/m1/schema-editor.md section 8). The layout
 * prints the breadcrumb, the flash notice, and the error summary before
 * this.
 *
 * Data: `field` {name}, `typeLabel`, `canChangeType`, `showValues` (bools),
 * `valueCount` (int) and `valueText` (formatted), `values`, `errors`,
 * `showRequired` (bool), `options` (data of field-options/options),
 * `action`, `cancel`, `typePath`, `deletePath` (application paths).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('schema.field.edit.title', ['field' => $data['field']['name']]) ?></h1>
<?php if ($data['canChangeType'] === true) : ?>
<p class="lead"><?= $this->t('schema.field.edit.type', ['type' => $data['typeLabel']]) ?> <a href="<?= $this->url($data['typePath']) ?>"><?= $this->t('schema.field.edit.change_type') ?></a></p>
<?php else : ?>
<p class="lead"><?= $this->t('schema.field.edit.type', ['type' => $data['typeLabel']]) ?> <?= $this->t('schema.field.edit.type_fixed') ?></p>
<?php endif; ?>
<?php if ($data['showValues'] !== true) : ?>
<?php elseif ($data['valueCount'] === 0) : ?>
<p><?= $this->t('schema.field.edit.values_none') ?></p>
<?php elseif ($data['valueCount'] === 1) : ?>
<p><?= $this->t('schema.field.edit.values_one') ?></p>
<?php else : ?>
<p><?= $this->t('schema.field.edit.values', ['count' => $data['valueText']]) ?></p>
<?php endif; ?>
</div>
<form class="form form-card form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('field-options/basics', ['values' => $data['values'], 'errors' => $data['errors'], 'showRequired' => $data['showRequired']]) ?>
<?= $this->partial('field-options/options', $data['options']) ?>
<?= $this->partial('form-actions', ['submit' => 'schema.field.edit.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
<section class="fieldset-card" aria-labelledby="delete-field-title">
<h2 class="legend" id="delete-field-title"><?= $this->t('schema.field.delete_title') ?></h2>
<p><?= $this->t('schema.field.delete_intro') ?></p>
<p><a class="button button-danger" href="<?= $this->url($data['deletePath']) ?>"><?= $this->t('schema.field.delete') ?></a></p>
</section>
