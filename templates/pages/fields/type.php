<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Change a field's type (docs/design/m1/schema-editor.md section 9). The
 * layout prints the breadcrumb and the error summary before this.
 *
 * Data: `field` {name}, `typeLabel`, `types` (the types it can change to: a list of
 * {id, label, hint (string keys)}),
 * `error` (plain text or null), `action` and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('schema.type.title', ['field' => $data['field']['name']]) ?></h1>
<p class="lead"><?= $this->t('schema.type.now', ['type' => $data['typeLabel']]) ?></p>
</div>
<p><?= $this->t('schema.type.intro') ?></p>
<form class="form form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('schema.type.question') ?></legend>
<div class="radio-grid">
<?php foreach ($data['types'] as $i => $type) : ?>
<label class="radio-card">
<?php if ($i === 0 && is_string($data['error'])) : ?>
<input id="type-choice" type="radio" name="type" value="<?= $this->e($type['id']) ?>" aria-describedby="type-choice-error" aria-invalid="true" />
<?php elseif ($i === 0) : ?>
<input id="type-choice" type="radio" name="type" value="<?= $this->e($type['id']) ?>" />
<?php elseif (is_string($data['error'])) : ?>
<input type="radio" name="type" value="<?= $this->e($type['id']) ?>" aria-describedby="type-choice-error" aria-invalid="true" />
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
<?php if (is_string($data['error'])) : ?>
<p class="error-text" id="type-choice-error"><?= $this->partial('field-error', ['text' => $data['error']]) ?></p>
<?php endif; ?>
</fieldset>
<?= $this->partial('form-actions', ['submit' => 'schema.type.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
