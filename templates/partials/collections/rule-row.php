<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One rule of the collection editor (docs/design/m4/collections.md
 * section 4): a fieldset with its legend, the field select (with the id
 * the error summary links to), the test select once a field is chosen,
 * the value control when the test takes one, Remove, then the row's
 * problems or the reason a stored rule no longer works.
 *
 * Data `row`: `index` (int from 1), `names` ({field, test, value, record}:
 * the posted names), `remove` (the Remove button's action), `fields` (list of {key, label, selected}), `tests`
 * (null, or list of {op, label (a message key), selected}), `value` (null,
 * or {control: text|decimal|date|select, name, value, label (plain),
 * options (list of {value, label, selected}), placeholder (bool: an empty
 * first option, selected, for a choice or record not chosen yet), help (a message key or
 * null), more (bool)}), `problems` (list of plain texts), `broken` (null or
 * a `collection.broken.*` message key).
 */

declare(strict_types=1);

?>
<?php $row = $data['row']; ?>
<?php $n = $row['index']; ?>
<?php $value = $row['value']; ?>
<?php $invalid = count($row['problems']) > 0; ?>
<?php $broken = is_string($row['broken']) && !$invalid; ?>
<?php if ($broken) : ?>
<fieldset class="rule-row rule-broken">
<?php else : ?>
<fieldset class="rule-row">
<?php endif; ?>
<legend><?= $this->t('collections.rule.legend', ['n' => $n]) ?></legend>
<?php if ($invalid) : ?>
<select class="input rule-control" id="<?= $this->id('f', 'rule', $n, 'field') ?>" name="<?= $this->e($row['names']['field']) ?>" aria-label="<?= $this->t('collections.rule.field_label', ['n' => $n]) ?>" data-rule-field="1" aria-describedby="<?= $this->id('f', 'rule', $n, 'problem') ?>" aria-invalid="true">
<?php elseif ($broken) : ?>
<select class="input rule-control" id="<?= $this->id('f', 'rule', $n, 'field') ?>" name="<?= $this->e($row['names']['field']) ?>" aria-label="<?= $this->t('collections.rule.field_label', ['n' => $n]) ?>" data-rule-field="1" aria-describedby="<?= $this->id('f', 'rule', $n, 'problem') ?>">
<?php else : ?>
<select class="input rule-control" id="<?= $this->id('f', 'rule', $n, 'field') ?>" name="<?= $this->e($row['names']['field']) ?>" aria-label="<?= $this->t('collections.rule.field_label', ['n' => $n]) ?>" data-rule-field="1">
<?php endif; ?>
<option value=""><?= $this->t('collections.rule.field_none') ?></option>
<?php foreach ($row['fields'] as $field) : ?>
<?php if ($field['selected'] === true) : ?>
<option value="<?= $this->e($field['key']) ?>" selected="selected"><?= $this->e($field['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($field['key']) ?>"><?= $this->e($field['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php if (is_array($row['tests'])) : ?>
<select class="input rule-control" name="<?= $this->e($row['names']['test']) ?>" aria-label="<?= $this->t('collections.rule.test_label', ['n' => $n]) ?>">
<?php foreach ($row['tests'] as $test) : ?>
<?php if ($test['selected'] === true) : ?>
<option value="<?= $this->e($test['op']) ?>" selected="selected"><?= $this->t($test['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($test['op']) ?>"><?= $this->t($test['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php endif; ?>
<?php if (is_array($value) && $value['control'] === 'select') : ?>
<select class="input rule-control rule-control-wide" name="<?= $this->e($value['name']) ?>" aria-label="<?= $this->e($value['label']) ?>">
<?php if ($value['placeholder'] === true) : ?>
<option value="" selected="selected"><?= $this->t('collections.rule.value_none') ?></option>
<?php endif; ?>
<?php foreach ($value['options'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['value']) ?>" selected="selected"><?= $this->e($option['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['value']) ?>"><?= $this->e($option['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php elseif (is_array($value) && $value['control'] === 'date') : ?>
<input class="input rule-control rule-control-wide" type="date" name="<?= $this->e($value['name']) ?>" value="<?= $this->e($value['value']) ?>" aria-label="<?= $this->e($value['label']) ?>" />
<?php elseif (is_array($value) && $value['control'] === 'decimal') : ?>
<input class="input rule-control rule-control-wide" type="text" inputmode="decimal" name="<?= $this->e($value['name']) ?>" value="<?= $this->e($value['value']) ?>" maxlength="200" autocomplete="off" aria-label="<?= $this->e($value['label']) ?>" />
<?php elseif (is_array($value)) : ?>
<input class="input rule-control rule-control-wide" type="text" name="<?= $this->e($value['name']) ?>" value="<?= $this->e($value['value']) ?>" maxlength="200" autocomplete="off" aria-label="<?= $this->e($value['label']) ?>" />
<?php endif; ?>
<button class="button button-plain" type="submit" name="action" value="<?= $this->e($row['remove']) ?>" aria-label="<?= $this->t('collections.rule.remove_label', ['n' => $n]) ?>"><?= $this->t('collections.rule.remove') ?></button>
<?php if (is_array($value) && is_string($value['help'])) : ?>
<p class="help rule-problem"><?= $this->t($value['help']) ?></p>
<?php endif; ?>
<?php if (is_array($value) && $value['more'] === true) : ?>
<p class="help rule-problem"><?= $this->t('collections.rule.records_more') ?></p>
<?php endif; ?>
<?php if ($invalid) : ?>
<p class="error-text rule-problem" id="<?= $this->id('f', 'rule', $n, 'problem') ?>">
<?php foreach ($row['problems'] as $i => $problem) : ?>
<?php if ($i > 0) : ?>
<br />
<?php endif; ?>
<?= $this->e($problem) ?>
<?php endforeach; ?>
</p>
<?php elseif ($broken) : ?>
<p class="rule-problem" id="<?= $this->id('f', 'rule', $n, 'problem') ?>"><?= $this->t('collections.rule.broken') ?> <?= $this->t($row['broken']) ?></p>
<?php endif; ?>
</fieldset>
