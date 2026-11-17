<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Add a field, step 1: choose the type (docs/design/m1/schema-editor.md
 * section 7.1). A GET form: choosing a type is view state. The layout
 * prints the breadcrumb and, when no valid type was chosen, the error
 * summary before this.
 *
 * Data: `table` {name}, `groups`: a list of {label (string key), types:
 * a list of {id, label, hint (string keys)}}, `error` (bool), `action` and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('schema.field.new.title', ['table' => $data['table']['name']]) ?></h1>
<form class="form form-wide" method="get" action="<?= $this->url($data['action']) ?>">
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('schema.field.type_question') ?></legend>
<?php if ($data['error'] === true) : ?>
<p class="error-text" id="field-type-error"><?= $this->t('schema.field.type_required') ?></p>
<?php endif; ?>
<?php foreach ($data['groups'] as $group) : ?>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t($group['label']) ?></legend>
<div class="radio-grid">
<?php foreach ($group['types'] as $type) : ?>
<label class="radio-card">
<?php if ($type['id'] === 'text' && $data['error'] === true) : ?>
<input id="field-type" type="radio" name="type" value="<?= $this->e($type['id']) ?>" aria-describedby="field-type-error" aria-invalid="true" />
<?php elseif ($type['id'] === 'text') : ?>
<input id="field-type" type="radio" name="type" value="<?= $this->e($type['id']) ?>" />
<?php elseif ($data['error'] === true) : ?>
<input type="radio" name="type" value="<?= $this->e($type['id']) ?>" aria-describedby="field-type-error" aria-invalid="true" />
<?php else : ?>
<input type="radio" name="type" value="<?= $this->e($type['id']) ?>" />
<?php endif; ?>
<span>
<span class="radio-card-title"><?= $this->t($type['label']) ?></span>
<span class="radio-card-hint"><?= $this->t($type['hint']) ?></span>
</span>
</label>
<?php endforeach; ?>
</div>
</fieldset>
<?php endforeach; ?>
</fieldset>
<?= $this->partial('form-actions', ['submit' => 'schema.field.next', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
