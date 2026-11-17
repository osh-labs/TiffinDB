<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of rating fields (docs/design/m1/schema-editor.md section 7.3):
 * the highest rating, 3 to 10.
 *
 * Data: `values` {opt_max}, `errors`.
 */

declare(strict_types=1);

?>
<div class="field">
<label class="label" for="opt-max"><?= $this->t('schema.opt.rating_max') ?></label>
<?php if (is_string($data['errors']['opt-max'] ?? null)) : ?>
<p class="error-text" id="opt-max-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-max']]) ?></p>
<select class="input input-short" id="opt-max" name="opt_max" aria-describedby="opt-max-error" aria-invalid="true">
<?php else : ?>
<select class="input input-short" id="opt-max" name="opt_max">
<?php endif; ?>
<?php foreach (['3', '4', '5', '6', '7', '8', '9', '10'] as $top) : ?>
<?php if ($top === $data['values']['opt_max']) : ?>
<option value="<?= $this->e($top) ?>" selected="selected"><?= $this->e($top) ?></option>
<?php else : ?>
<option value="<?= $this->e($top) ?>"><?= $this->e($top) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
