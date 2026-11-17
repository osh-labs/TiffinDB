<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Delete a table: the confirmation page (docs/design/m1/schema-editor.md
 * section 6). The layout prints the breadcrumb and the error summary
 * before this.
 *
 * Data: `table` {name}, `records` (counted text, for example "3
 * records"), `links`: a list of {field, table} in other tables, `error`
 * (bool: the box was not ticked), `action` and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('schema.table.delete_confirm.title', ['table' => $data['table']['name']]) ?></h1>
<p><?= $this->t('schema.table.delete_confirm.body', ['records' => $data['records']]) ?></p>
<?php if (count($data['links']) > 0) : ?>
<p><?= $this->t('schema.table.delete_confirm.links') ?></p>
<ul>
<?php foreach ($data['links'] as $link) : ?>
<li><?= $this->t('schema.table.delete_confirm.link', ['field' => $link['field'], 'table' => $link['table']]) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<p><?= $this->t('schema.delete.copy') ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<?php if ($data['error'] === true) : ?>
<label class="check">
<input id="delete-confirm" type="checkbox" name="confirm" value="1" required="required" aria-describedby="delete-confirm-error" aria-invalid="true" />
<span><?= $this->t('schema.table.delete_confirm.check', ['records' => $data['records']]) ?></span>
</label>
<p class="error-text" id="delete-confirm-error"><?= $this->t('schema.delete.confirm_required') ?></p>
<?php else : ?>
<label class="check">
<input id="delete-confirm" type="checkbox" name="confirm" value="1" required="required" />
<span><?= $this->t('schema.table.delete_confirm.check', ['records' => $data['records']]) ?></span>
</label>
<?php endif; ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'schema.table.delete', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
