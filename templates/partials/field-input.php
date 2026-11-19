<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The control for one editable field of a record form (M2 spec part 2
 * section 5.1; docs/design/m2/record.md section 2.1). The caller prints
 * the label, help, and errors around it; this partial prints only:
 *
 * - an `input`, `textarea`, or `select` with id `id('f', key)`, name
 *   `f_<key>`, and the type's attributes;
 * - for a checkbox, the wrapping `label.check.label` holding the checkbox
 *   and the label text (with the required marker when required);
 * - for a rating, `div.choice-row` of radios, 1 to the maximum and then
 *   "Not rated"; the first radio has `id('f', key)`. The caller wraps it in
 *   `fieldset.field-group` with the legend.
 *
 * Types without an input (auto-number, relation, attachment) print
 * nothing. Values are the bound field's text through inputValue().
 *
 * Data: `field` (FieldDef), `key` (field key), `text` (the bound field's
 * inputText), `submitted` (bool: the text was typed), `required` (bool),
 * `invalid` (bool), `describedBy` (list holding `help` and/or `err` when
 * the caller prints those paragraphs), `label` (plain text), and
 * `currencyName` (plain text or null; the caller uses it in the label).
 */

declare(strict_types=1);

?>
<?php $facts = $this->fieldFacts($data['field']); ?>
<?php $help = in_array('help', $data['describedBy'] ?? [], true); ?>
<?php $err = in_array('err', $data['describedBy'] ?? [], true); ?>
<?php if ($facts['type'] === 'long_text') : ?>
<textarea class="input" rows="4" maxlength="<?= $this->e($facts['maxLength']) ?>" id="<?= $this->id('f', $data['key']) ?>" name="<?= $this->e('f_' . $data['key']) ?>"<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?>><?= $this->inputValue($data['field'], $data['text'], $data['submitted']) ?></textarea>
<?php elseif ($facts['type'] === 'choice') : ?>
<select class="input" id="<?= $this->id('f', $data['key']) ?>" name="<?= $this->e('f_' . $data['key']) ?>"<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?>>
<option value=""><?= $this->t('input.choice.none') ?></option>
<?php foreach ($facts['choices'] as $choice) : ?>
<?php if ($choice === $data['text']) : ?>
<option value="<?= $this->e($choice) ?>" selected="selected"><?= $this->e($choice) ?></option>
<?php else : ?>
<option value="<?= $this->e($choice) ?>"><?= $this->e($choice) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php elseif ($facts['type'] === 'checkbox') : ?>
<label class="check label"><input type="checkbox" value="1" id="<?= $this->id('f', $data['key']) ?>" name="<?= $this->e('f_' . $data['key']) ?>"<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?><?php if ($data['text'] === '1') : ?> checked="checked"<?php endif; ?> /><span><?= $this->e($data['label']) ?><?php if ($data['required'] === true) : ?> <span class="label-optional"><?= $this->t('input.required') ?></span><?php endif; ?></span></label>
<?php elseif ($facts['type'] === 'rating') : ?>
<div class="choice-row">
<?php for ($n = 1; $n <= $facts['max']; $n++) : ?>
<label class="check"><input type="radio"<?php if ($n === 1) : ?> id="<?= $this->id('f', $data['key']) ?>"<?php endif; ?> name="<?= $this->e('f_' . $data['key']) ?>" value="<?= $this->e($n) ?>"<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?><?php if ($data['text'] === (string) $n) : ?> checked="checked"<?php endif; ?> /><?php if ($n === 1) : ?><span><?= $this->t('input.rating.low', ['n' => $n]) ?></span><?php elseif ($n === $facts['max']) : ?><span><?= $this->t('input.rating.high', ['n' => $n]) ?></span><?php else : ?><span><?= $this->e($n) ?></span><?php endif; ?></label>
<?php endfor; ?>
<label class="check"><input type="radio" name="<?= $this->e('f_' . $data['key']) ?>" value=""<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?><?php if ($data['text'] === '') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('input.rating.none') ?></span></label>
</div>
<?php elseif ($facts['editable'] === true) : ?>
<input class="input"<?php if ($facts['type'] === 'text') : ?> type="text" maxlength="<?= $this->e($facts['maxLength']) ?>"<?php elseif ($facts['type'] === 'number' && $facts['decimals'] === 0) : ?> type="text" inputmode="numeric"<?php elseif ($facts['type'] === 'number' || $facts['type'] === 'currency') : ?> type="text" inputmode="decimal"<?php elseif ($facts['type'] === 'date') : ?> type="date"<?php elseif ($facts['type'] === 'time' && $facts['seconds'] === true) : ?> type="time" step="1"<?php elseif ($facts['type'] === 'time') : ?> type="time" step="60"<?php elseif ($facts['type'] === 'date_time' && $facts['seconds'] === true) : ?> type="datetime-local" step="1"<?php elseif ($facts['type'] === 'date_time') : ?> type="datetime-local" step="60"<?php elseif ($facts['type'] === 'url') : ?> type="url" maxlength="2048"<?php elseif ($facts['type'] === 'email') : ?> type="email" maxlength="254"<?php elseif ($facts['type'] === 'phone') : ?> type="tel" maxlength="40"<?php else : ?> type="text"<?php endif; ?> id="<?= $this->id('f', $data['key']) ?>" name="<?= $this->e('f_' . $data['key']) ?>" value="<?= $this->inputValue($data['field'], $data['text'], $data['submitted']) ?>"<?php if ($help && $err) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"<?php elseif ($help) : ?> aria-describedby="<?= $this->id('help', $data['key']) ?>"<?php elseif ($err) : ?> aria-describedby="<?= $this->id('err', $data['key']) ?>"<?php endif; ?><?php if ($data['invalid'] === true) : ?> aria-invalid="true"<?php endif; ?><?php if ($data['required'] === true) : ?> required="required"<?php endif; ?> />
<?php endif; ?>
