<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Confirm clearing values (docs/design/m1/schema-editor.md section 11):
 * the answer, status 409, to a field change or type change that would
 * clear stored values. The form posts every submitted field again, except
 * `_csrf` (the current token is printed) and `confirm` (P59), with
 * `confirm` = 1.
 *
 * Data: `one` (bool: exactly one value), `bodyKey` (string key of the
 * first paragraph, singular or plural), `values` and `records`
 * (formatted counts), `hidden` (list of {name, value}), `action`
 * and `back` (application paths).
 */

declare(strict_types=1);

?>
<?php if ($data['one'] === true) : ?>
<h1><?= $this->t('schema.confirm.title_one') ?></h1>
<?php else : ?>
<h1><?= $this->t('schema.confirm.title', ['values' => $data['values']]) ?></h1>
<?php endif; ?>
<p><?= $this->t($data['bodyKey'], ['values' => $data['values'], 'records' => $data['records']]) ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?php foreach ($data['hidden'] as $field) : ?>
<input type="hidden" name="<?= $this->e($field['name']) ?>" value="<?= $this->e($field['value']) ?>" />
<?php endforeach; ?>
<input type="hidden" name="confirm" value="1" />
<?php if ($data['one'] === true) : ?>
<?= $this->partial('form-actions', ['submit' => 'schema.confirm.submit_one', 'danger' => true, 'cancel' => $data['back'], 'cancelLabel' => 'schema.confirm.back']) ?>
<?php else : ?>
<?= $this->partial('form-actions', ['submit' => 'schema.confirm.submit', 'submitParams' => ['values' => $data['values']], 'danger' => true, 'cancel' => $data['back'], 'cancelLabel' => 'schema.confirm.back']) ?>
<?php endif; ?>
</form>
