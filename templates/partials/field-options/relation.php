<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of link fields (docs/design/m1/schema-editor.md section 7.3).
 * New: the linked table, how many records each side may link to, and the
 * name of the matching field in the linked table. Edit: the linked table
 * as text, and how many records each record here may link to. Nothing
 * depends on the selected table, so no script is needed.
 *
 * Data: `mode`, `values` {opt_this_max; new: opt_target, opt_other_max,
 * opt_reverse_name}, `errors`, `tables` ({key, name, isThis}, new),
 * `linkedTable` (edit), `itemName`.
 */

declare(strict_types=1);

?>
<?php if ($data['mode'] === 'new') : ?>
<div class="field">
<label class="label" for="opt-target"><?= $this->t('schema.opt.target') ?></label>
<?php if (is_string($data['errors']['opt-target'] ?? null)) : ?>
<p class="error-text" id="opt-target-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-target']]) ?></p>
<select class="input" id="opt-target" name="opt_target" aria-describedby="opt-target-error" aria-invalid="true">
<?php else : ?>
<select class="input" id="opt-target" name="opt_target">
<?php endif; ?>
<?php foreach ($data['tables'] as $table) : ?>
<?php if ($table['key'] === $data['values']['opt_target'] && $table['isThis'] === true) : ?>
<option value="<?= $this->e($table['key']) ?>" selected="selected"><?= $this->t('schema.opt.target_this', ['table' => $table['name']]) ?></option>
<?php elseif ($table['key'] === $data['values']['opt_target']) : ?>
<option value="<?= $this->e($table['key']) ?>" selected="selected"><?= $this->e($table['name']) ?></option>
<?php elseif ($table['isThis'] === true) : ?>
<option value="<?= $this->e($table['key']) ?>"><?= $this->t('schema.opt.target_this', ['table' => $table['name']]) ?></option>
<?php else : ?>
<option value="<?= $this->e($table['key']) ?>"><?= $this->e($table['name']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php else : ?>
<p><?= $this->t('schema.opt.links_to', ['table' => $data['linkedTable']]) ?></p>
<?php endif; ?>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('schema.opt.this_max', ['item' => $data['itemName']]) ?></legend>
<?php if (is_string($data['errors']['opt-this-max'] ?? null)) : ?>
<p class="error-text" id="opt-this-max-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-this-max']]) ?></p>
<?php endif; ?>
<label class="check">
<?php if ($data['values']['opt_this_max'] === 'one') : ?>
<input id="opt-this-max" type="radio" name="opt_this_max" value="one" checked="checked" />
<?php else : ?>
<input id="opt-this-max" type="radio" name="opt_this_max" value="one" />
<?php endif; ?>
<span><?= $this->t('schema.opt.max.one') ?></span>
</label>
<label class="check">
<?php if ($data['values']['opt_this_max'] === 'many') : ?>
<input type="radio" name="opt_this_max" value="many" checked="checked" />
<?php else : ?>
<input type="radio" name="opt_this_max" value="many" />
<?php endif; ?>
<span><?= $this->t('schema.opt.max.many') ?></span>
</label>
</fieldset>
<?php if ($data['mode'] === 'new') : ?>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('schema.opt.other_max') ?></legend>
<?php if (is_string($data['errors']['opt-other-max'] ?? null)) : ?>
<p class="error-text" id="opt-other-max-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-other-max']]) ?></p>
<?php endif; ?>
<label class="check">
<?php if ($data['values']['opt_other_max'] === 'one') : ?>
<input id="opt-other-max" type="radio" name="opt_other_max" value="one" checked="checked" />
<?php else : ?>
<input id="opt-other-max" type="radio" name="opt_other_max" value="one" />
<?php endif; ?>
<span><?= $this->t('schema.opt.other.one', ['item' => $data['itemName']]) ?></span>
</label>
<label class="check">
<?php if ($data['values']['opt_other_max'] === 'many') : ?>
<input type="radio" name="opt_other_max" value="many" checked="checked" />
<?php else : ?>
<input type="radio" name="opt_other_max" value="many" />
<?php endif; ?>
<span><?= $this->t('schema.opt.other.many') ?></span>
</label>
</fieldset>
<div class="field">
<label class="label" for="opt-reverse-name"><?= $this->t('schema.opt.reverse_name') ?></label>
<p class="help" id="opt-reverse-name-help"><?= $this->t('schema.opt.reverse_name_help') ?></p>
<?php if (is_string($data['errors']['opt-reverse-name'] ?? null)) : ?>
<p class="error-text" id="opt-reverse-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-reverse-name']]) ?></p>
<input class="input" id="opt-reverse-name" type="text" name="opt_reverse_name" value="<?= $this->e($data['values']['opt_reverse_name']) ?>" autocomplete="off" aria-describedby="opt-reverse-name-help opt-reverse-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="opt-reverse-name" type="text" name="opt_reverse_name" value="<?= $this->e($data['values']['opt_reverse_name']) ?>" autocomplete="off" aria-describedby="opt-reverse-name-help" />
<?php endif; ?>
</div>
<?php endif; ?>
