<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Confirm clearing values (docs/design/m1/schema-editor.md section 11;
 * docs/design/m2/states.md section 3): the answer, status 409, to a field
 * change or type change that would clear stored values. The form posts
 * every submitted field again, except `_csrf` (the current token is
 * printed) and `confirm` (P59), with `confirm` = the number of values this
 * page shows; the core asks again when the real number is larger (P82).
 *
 * Data: `values` and `records` (int counts, worded by the plural
 * placeholder), `hidden` (list of {name, value}), `action` and `back`
 * (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('schema.confirm.title', ['values' => $data['values']]) ?></h1>
<p><?= $this->t('schema.confirm.body', ['values' => $data['values'], 'records' => $data['records']]) ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?php foreach ($data['hidden'] as $field) : ?>
<input type="hidden" name="<?= $this->e($field['name']) ?>" value="<?= $this->e($field['value']) ?>" />
<?php endforeach; ?>
<input type="hidden" name="confirm" value="<?= $this->e($data['values']) ?>" />
<?= $this->partial('form-actions', ['submit' => 'schema.confirm.submit', 'submitParams' => ['values' => $data['values']], 'danger' => true, 'cancel' => $data['back'], 'cancelLabel' => 'schema.confirm.back']) ?>
</form>
