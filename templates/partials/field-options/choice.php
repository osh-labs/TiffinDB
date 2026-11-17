<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of choice fields (docs/design/m1/schema-editor.md section 7.3).
 * New: the choices, one per line. Edit: one box per existing choice
 * (renaming it renames the stored values; emptying it removes the
 * choice), then more choices, one per line. The repeated boxes are named
 * by aria-label, since ids cannot be generated (M1 spec part 2 section
 * 4.5 item 6).
 *
 * Data: `mode`, `values` {opt_choices}, `errors`, `choices` (edit:
 * {name, value, number}).
 */

declare(strict_types=1);

?>
<?php if ($data['mode'] === 'new') : ?>
<div class="field">
<label class="label" for="opt-choices"><?= $this->t('schema.opt.choices') ?></label>
<p class="help" id="opt-choices-help"><?= $this->t('schema.opt.choices_help') ?></p>
<?php if (is_string($data['errors']['opt-choices'] ?? null)) : ?>
<p class="error-text" id="opt-choices-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-choices']]) ?></p>
<textarea class="input" id="opt-choices" name="opt_choices" rows="6" aria-describedby="opt-choices-help opt-choices-error" aria-invalid="true"><?= $this->e($data['values']['opt_choices']) ?></textarea>
<?php else : ?>
<textarea class="input" id="opt-choices" name="opt_choices" rows="6" aria-describedby="opt-choices-help"><?= $this->e($data['values']['opt_choices']) ?></textarea>
<?php endif; ?>
</div>
<?php else : ?>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('schema.opt.choices_existing') ?></legend>
<p class="help" id="opt-choices-edit-help"><?= $this->t('schema.opt.choices_edit_help') ?></p>
<?php foreach ($data['choices'] as $choice) : ?>
<input class="input" type="text" name="<?= $this->e($choice['name']) ?>" value="<?= $this->e($choice['value']) ?>" autocomplete="off" aria-label="<?= $this->t('schema.opt.choice_label', ['number' => $choice['number']]) ?>" aria-describedby="opt-choices-edit-help" />
<?php endforeach; ?>
</fieldset>
<div class="field">
<label class="label" for="opt-choices-new"><?= $this->partial('field-label', ['key' => 'schema.opt.choices_add', 'optional' => true]) ?></label>
<?php if (is_string($data['errors']['opt-choices-new'] ?? null)) : ?>
<p class="error-text" id="opt-choices-new-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-choices-new']]) ?></p>
<textarea class="input" id="opt-choices-new" name="opt_choices" rows="3" aria-describedby="opt-choices-new-error" aria-invalid="true"><?= $this->e($data['values']['opt_choices']) ?></textarea>
<?php else : ?>
<textarea class="input" id="opt-choices-new" name="opt_choices" rows="3"><?= $this->e($data['values']['opt_choices']) ?></textarea>
<?php endif; ?>
</div>
<?php endif; ?>
