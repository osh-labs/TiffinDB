<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of currency fields (docs/design/m1/schema-editor.md section
 * 7.3): the currency code, and the digits after the point, which can be
 * chosen only when the field is made.
 *
 * Data: `mode`, `values` {opt_code, opt_minor_digits (new)}, `errors`,
 * `digits` (edit: the stored digits).
 */

declare(strict_types=1);

?>
<div class="field">
<label class="label" for="opt-code"><?= $this->t('schema.opt.code') ?></label>
<p class="help" id="opt-code-help"><?= $this->t('schema.opt.code_help') ?></p>
<?php if (is_string($data['errors']['opt-code'] ?? null)) : ?>
<p class="error-text" id="opt-code-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-code']]) ?></p>
<input class="input input-short" id="opt-code" type="text" name="opt_code" maxlength="3" autocapitalize="characters" value="<?= $this->e($data['values']['opt_code']) ?>" aria-describedby="opt-code-help opt-code-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-short" id="opt-code" type="text" name="opt_code" maxlength="3" autocapitalize="characters" value="<?= $this->e($data['values']['opt_code']) ?>" aria-describedby="opt-code-help" />
<?php endif; ?>
</div>
<?php if ($data['mode'] === 'new') : ?>
<div class="field">
<label class="label" for="opt-minor-digits"><?= $this->t('schema.opt.minor_digits') ?></label>
<p class="help" id="opt-minor-digits-help"><?= $this->t('schema.opt.minor_digits_help') ?></p>
<?php if (is_string($data['errors']['opt-minor-digits'] ?? null)) : ?>
<p class="error-text" id="opt-minor-digits-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-minor-digits']]) ?></p>
<select class="input input-short" id="opt-minor-digits" name="opt_minor_digits" aria-describedby="opt-minor-digits-help opt-minor-digits-error" aria-invalid="true">
<?php else : ?>
<select class="input input-short" id="opt-minor-digits" name="opt_minor_digits" aria-describedby="opt-minor-digits-help">
<?php endif; ?>
<?php foreach (['0', '1', '2', '3', '4'] as $digits) : ?>
<?php if ($digits === $data['values']['opt_minor_digits']) : ?>
<option value="<?= $this->e($digits) ?>" selected="selected"><?= $this->e($digits) ?></option>
<?php else : ?>
<option value="<?= $this->e($digits) ?>"><?= $this->e($digits) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php else : ?>
<p><?= $this->t('schema.opt.minor_digits_fixed', ['digits' => $data['digits']]) ?></p>
<?php endif; ?>
