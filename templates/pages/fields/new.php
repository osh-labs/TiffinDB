<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Add a field, step 2: name and settings (docs/design/m1/schema-editor.md
 * section 7.2). The layout prints the breadcrumb and the error summary
 * before this.
 *
 * Data: `type` (id), `typeLabel`, `table` {name}, `values`, `errors`,
 * `showRequired` (bool), `options` (data of field-options/options),
 * `action`, `cancel`, and `chooseType` (application paths).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('schema.field.new.type_title', ['type' => $data['typeLabel']]) ?></h1>
<p class="lead"><?= $this->t('schema.field.new.type_lead', ['table' => $data['table']['name']]) ?> <a href="<?= $this->url($data['chooseType']) ?>"><?= $this->t('schema.field.change_type_choice') ?></a></p>
</div>
<form class="form form-card form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="type" value="<?= $this->e($data['type']) ?>" />
<?= $this->partial('field-options/basics', ['values' => $data['values'], 'errors' => $data['errors'], 'showRequired' => $data['showRequired']]) ?>
<?= $this->partial('field-options/options', $data['options']) ?>
<?= $this->partial('form-actions', ['submit' => 'schema.field.new.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
