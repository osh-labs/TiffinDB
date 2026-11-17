<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of number fields (docs/design/m1/schema-editor.md section 7.3):
 * digits after the point, smallest and largest allowed.
 *
 * Data: `values` {opt_decimals, opt_min, opt_max}, `errors`.
 */

declare(strict_types=1);

?>
<div class="field">
<label class="label" for="opt-decimals"><?= $this->t('schema.opt.decimals') ?></label>
<?php if (is_string($data['errors']['opt-decimals'] ?? null)) : ?>
<p class="error-text" id="opt-decimals-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-decimals']]) ?></p>
<select class="input input-short" id="opt-decimals" name="opt_decimals" aria-describedby="opt-decimals-error" aria-invalid="true">
<?php else : ?>
<select class="input input-short" id="opt-decimals" name="opt_decimals">
<?php endif; ?>
<?php foreach (['0', '1', '2', '3', '4', '5', '6'] as $digits) : ?>
<?php if ($digits === $data['values']['opt_decimals']) : ?>
<option value="<?= $this->e($digits) ?>" selected="selected"><?= $this->e($digits) ?></option>
<?php else : ?>
<option value="<?= $this->e($digits) ?>"><?= $this->e($digits) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="field">
<label class="label" for="opt-min"><?= $this->partial('field-label', ['key' => 'schema.opt.min', 'optional' => true]) ?></label>
<?php if (is_string($data['errors']['opt-min'] ?? null)) : ?>
<p class="error-text" id="opt-min-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-min']]) ?></p>
<input class="input input-short" id="opt-min" type="text" name="opt_min" inputmode="decimal" value="<?= $this->e($data['values']['opt_min']) ?>" aria-describedby="opt-min-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-short" id="opt-min" type="text" name="opt_min" inputmode="decimal" value="<?= $this->e($data['values']['opt_min']) ?>" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="opt-max"><?= $this->partial('field-label', ['key' => 'schema.opt.max', 'optional' => true]) ?></label>
<?php if (is_string($data['errors']['opt-max'] ?? null)) : ?>
<p class="error-text" id="opt-max-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-max']]) ?></p>
<input class="input input-short" id="opt-max" type="text" name="opt_max" inputmode="decimal" value="<?= $this->e($data['values']['opt_max']) ?>" aria-describedby="opt-max-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-short" id="opt-max" type="text" name="opt_max" inputmode="decimal" value="<?= $this->e($data['values']['opt_max']) ?>" />
<?php endif; ?>
</div>
