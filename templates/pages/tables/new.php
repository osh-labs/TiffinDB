<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * New table (docs/design/m1/schema-editor.md section 4). The layout
 * prints the breadcrumb and the error summary before this.
 *
 * Data: `values` {name, item_name, with_name (bool)}, `errors` (element
 * id => plain text), `action` and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('schema.table.new.title') ?></h1>
<p class="lead"><?= $this->t('schema.table.new.lead') ?></p>
</div>
<form class="form form-card form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="table-name"><?= $this->t('schema.table.name') ?></label>
<p class="help" id="table-name-help"><?= $this->t('schema.table.name_help') ?></p>
<?php if (is_string($data['errors']['table-name'] ?? null)) : ?>
<p class="error-text" id="table-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['table-name']]) ?></p>
<input class="input" id="table-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="table-name-help table-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="table-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="table-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="table-item"><?= $this->t('schema.table.item') ?></label>
<p class="help" id="table-item-help"><?= $this->t('schema.table.item_help') ?></p>
<?php if (is_string($data['errors']['table-item'] ?? null)) : ?>
<p class="error-text" id="table-item-error"><?= $this->partial('field-error', ['text' => $data['errors']['table-item']]) ?></p>
<input class="input input-medium" id="table-item" type="text" name="item_name" value="<?= $this->e($data['values']['item_name']) ?>" autocomplete="off" required="required" aria-describedby="table-item-help table-item-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="table-item" type="text" name="item_name" value="<?= $this->e($data['values']['item_name']) ?>" autocomplete="off" required="required" aria-describedby="table-item-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="check">
<?php if ($data['values']['with_name'] === true) : ?>
<input id="table-with-name" type="checkbox" name="with_name" value="1" checked="checked" aria-describedby="table-with-name-help" />
<?php else : ?>
<input id="table-with-name" type="checkbox" name="with_name" value="1" aria-describedby="table-with-name-help" />
<?php endif; ?>
<span><?= $this->t('schema.table.with_name') ?></span>
</label>
<p class="help" id="table-with-name-help"><?= $this->t('schema.table.with_name_help') ?></p>
</div>
<?= $this->partial('form-actions', ['submit' => 'schema.table.new.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
