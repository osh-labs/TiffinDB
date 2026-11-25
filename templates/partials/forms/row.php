<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One row of the form editor (docs/design/m5/components.md section 4;
 * docs/design/m5/editor.md section 3), inside the editor's one form: a
 * `li.form-row` with its head, its inputs (label and width for a field,
 * section name for a section), notes, and its buttons, each
 * `button type="submit" name="do"` with the value of M5 spec part 2
 * section 5.2. A section's child rows are written by this partial again,
 * in a nested `ol.form-rows.form-row-children`. A move that cannot
 * happen is not shown. Exactly the control the core chose (`focus`)
 * carries `autofocus` (section 5.4).
 *
 * Data: `row` = {path ('3' or '3-2', checked by EditorPath), kind
 * ('field', 'missing', 'section', 'heading', 'paragraph', 'divider',
 * 'image'), name (plain text, cut to 60 characters by the controller: the
 * field's name, the section's name or null for none, the key of a
 * missing slot, or for a static row the kind and its summary, "Heading
 * Where it is"; a static row without one is named by its kind),
 * typeLabel (plain text, fields), required (bool), label (the label
 * box's value), legend (the section name box's value), width ('auto',
 * 'full', 'two-thirds', 'half', 'one-third', 'other'), span (the stored
 * span, shown for 'other'), placed (bool), summary (plain text), imageHidden
 * (bool), canMoveUp, canMoveDown (bools), children (list of rows, for a
 * section), errors (input id, such as `label-3-2`, => problem text),
 * focus (null, 'up', 'down', 'first' (the row's first button), or
 * 'legend')}; `number` (int: the section's number, from 1 in reading
 * order; passed on to child rows unchanged).
 *
 * Every text is plain and escaped here. Paths reach `id`, `name`, and
 * `value` only through id() and e().
 */

declare(strict_types=1);

?>
<?php $row = $data['row']; ?>
<?php $kind = $row['kind']; ?>
<?php $named = is_string($row['name']) && $row['name'] !== ''; ?>
<?php $unnamedStatic = !$named && in_array($kind, ['heading', 'paragraph', 'divider', 'image'], true); ?>
<?php $kindKey = $kind === 'heading' ? 'forms.row.kind.heading' : ($kind === 'paragraph' ? 'forms.row.kind.paragraph' : ($kind === 'divider' ? 'forms.row.kind.divider' : 'forms.row.kind.image')); ?>
<?php $first = $row['canMoveUp'] === true ? 'up' : ($row['canMoveDown'] === true ? 'down' : 'last'); ?>
<?php $focus = $row['focus'] === 'first' ? $first : $row['focus']; ?>
<?php $labelError = $row['errors']['label-' . $row['path']] ?? null; ?>
<?php $legendError = $row['errors']['legend-' . $row['path']] ?? null; ?>
<?php if ($kind === 'section') : ?>
<li class="form-row form-row-section" id="<?= $this->id('row', $row['path']) ?>">
<?php elseif ($kind === 'missing') : ?>
<li class="form-row form-row-missing" id="<?= $this->id('row', $row['path']) ?>">
<?php else : ?>
<li class="form-row" id="<?= $this->id('row', $row['path']) ?>">
<?php endif; ?>
<div class="form-row-head">
<?php if ($kind === 'field') : ?>
<span class="form-row-name"><?= $this->e($row['name']) ?></span>
<span class="form-row-kind"><?= $this->e($row['typeLabel']) ?></span>
<?php if ($row['required'] === true) : ?>
<span class="form-row-kind"><?= $this->t('forms.row.required') ?></span>
<?php endif; ?>
<?php elseif ($kind === 'missing') : ?>
<span class="form-row-name"><?= $this->e($row['name']) ?></span>
<?php elseif ($kind === 'section') : ?>
<span class="form-row-kind"><?= $this->t('forms.row.kind.section') ?></span>
<?php if ($named) : ?>
<span class="form-row-name"><?= $this->e($row['name']) ?></span>
<?php else : ?>
<span class="form-row-name"><?= $this->t('forms.row.section_unnamed', ['number' => $data['number']]) ?></span>
<?php endif; ?>
<?php elseif ($kind === 'heading') : ?>
<span class="form-row-kind"><?= $this->t('forms.row.kind.heading') ?></span>
<?php elseif ($kind === 'paragraph') : ?>
<span class="form-row-kind"><?= $this->t('forms.row.kind.paragraph') ?></span>
<?php elseif ($kind === 'divider') : ?>
<span class="form-row-kind"><?= $this->t('forms.row.kind.divider') ?></span>
<?php elseif ($kind === 'image') : ?>
<span class="form-row-kind"><?= $this->t('forms.row.kind.image') ?></span>
<?php endif; ?>
</div>
<?php if ($kind === 'missing') : ?>
<p class="help form-row-note"><?= $this->t('forms.row.kind.missing') ?></p>
<?php elseif ($kind === 'heading' || $kind === 'paragraph') : ?>
<p class="form-row-summary"><?= $this->e($row['summary']) ?></p>
<?php elseif ($kind === 'divider') : ?>
<p class="form-row-summary"><?= $this->t('forms.row.divider') ?></p>
<?php elseif ($kind === 'image' && $row['summary'] !== '') : ?>
<p class="form-row-summary"><?= $this->t('forms.row.image_alt', ['alt' => $row['summary']]) ?></p>
<?php elseif ($kind === 'image') : ?>
<p class="form-row-summary"><?= $this->t('forms.row.image_no_alt') ?></p>
<?php endif; ?>
<?php if ($kind === 'image' && $row['imageHidden'] === true) : ?>
<p class="help form-row-note"><?= $this->t('forms.row.image_hidden') ?></p>
<?php endif; ?>
<?php if ($kind === 'field') : ?>
<div class="form-row-inputs">
<div class="field">
<label class="label" for="<?= $this->id('label', $row['path']) ?>"><?= $this->t('forms.row.label') ?></label>
<?php if (is_string($labelError)) : ?>
<p class="error-text" id="<?= $this->id('err-label', $row['path']) ?>"><?= $this->partial('field-error', ['text' => $labelError]) ?></p>
<input class="input" type="text" id="<?= $this->id('label', $row['path']) ?>" name="<?= $this->e('label[' . $row['path'] . ']') ?>" value="<?= $this->e($row['label']) ?>" maxlength="60" aria-label="<?= $this->t('forms.row.label_aria', ['field' => $row['name']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?> <?= $this->id('err-label', $row['path']) ?>" aria-invalid="true" />
<?php else : ?>
<input class="input" type="text" id="<?= $this->id('label', $row['path']) ?>" name="<?= $this->e('label[' . $row['path'] . ']') ?>" value="<?= $this->e($row['label']) ?>" maxlength="60" aria-label="<?= $this->t('forms.row.label_aria', ['field' => $row['name']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?>" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="<?= $this->id('width', $row['path']) ?>"><?= $this->t('forms.row.width') ?></label>
<select class="input" id="<?= $this->id('width', $row['path']) ?>" name="<?= $this->e('width[' . $row['path'] . ']') ?>" aria-label="<?= $this->t('forms.row.width_aria', ['field' => $row['name']]) ?>">
<option value="auto"<?php if ($row['width'] === 'auto') : ?> selected="selected"<?php endif; ?>><?= $this->t('forms.width.auto') ?></option>
<option value="full"<?php if ($row['width'] === 'full') : ?> selected="selected"<?php endif; ?>><?= $this->t('forms.width.full') ?></option>
<option value="two-thirds"<?php if ($row['width'] === 'two-thirds') : ?> selected="selected"<?php endif; ?>><?= $this->t('forms.width.two-thirds') ?></option>
<option value="half"<?php if ($row['width'] === 'half') : ?> selected="selected"<?php endif; ?>><?= $this->t('forms.width.half') ?></option>
<option value="one-third"<?php if ($row['width'] === 'one-third') : ?> selected="selected"<?php endif; ?>><?= $this->t('forms.width.one-third') ?></option>
<?php if ($row['width'] === 'other') : ?>
<option value="other" selected="selected"><?= $this->t('forms.width.other', ['span' => (string) $row['span']]) ?></option>
<?php endif; ?>
</select>
</div>
</div>
<?php elseif ($kind === 'section') : ?>
<div class="form-row-inputs">
<div class="field">
<label class="label" for="<?= $this->id('legend', $row['path']) ?>"><?= $this->t('forms.row.legend') ?></label>
<?php if (is_string($legendError)) : ?>
<p class="error-text" id="<?= $this->id('err-legend', $row['path']) ?>"><?= $this->partial('field-error', ['text' => $legendError]) ?></p>
<?php endif; ?>
<?php if (is_string($legendError) && $focus === 'legend') : ?>
<input class="input" type="text" id="<?= $this->id('legend', $row['path']) ?>" name="<?= $this->e('legend[' . $row['path'] . ']') ?>" value="<?= $this->e($row['legend']) ?>" maxlength="100" aria-label="<?= $this->t('forms.row.legend_aria', ['number' => $data['number']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?> <?= $this->id('err-legend', $row['path']) ?>" aria-invalid="true" autofocus="autofocus" />
<?php elseif (is_string($legendError)) : ?>
<input class="input" type="text" id="<?= $this->id('legend', $row['path']) ?>" name="<?= $this->e('legend[' . $row['path'] . ']') ?>" value="<?= $this->e($row['legend']) ?>" maxlength="100" aria-label="<?= $this->t('forms.row.legend_aria', ['number' => $data['number']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?> <?= $this->id('err-legend', $row['path']) ?>" aria-invalid="true" />
<?php elseif ($focus === 'legend') : ?>
<input class="input" type="text" id="<?= $this->id('legend', $row['path']) ?>" name="<?= $this->e('legend[' . $row['path'] . ']') ?>" value="<?= $this->e($row['legend']) ?>" maxlength="100" aria-label="<?= $this->t('forms.row.legend_aria', ['number' => $data['number']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?>" autofocus="autofocus" />
<?php else : ?>
<input class="input" type="text" id="<?= $this->id('legend', $row['path']) ?>" name="<?= $this->e('legend[' . $row['path'] . ']') ?>" value="<?= $this->e($row['legend']) ?>" maxlength="100" aria-label="<?= $this->t('forms.row.legend_aria', ['number' => $data['number']]) ?>" aria-describedby="<?= $this->id('editor', 'help') ?>" />
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<?php if ($row['placed'] === true) : ?>
<p class="help form-row-note"><?= $this->t('forms.row.placed') ?></p>
<?php endif; ?>
<div class="form-row-actions">
<?php if ($row['canMoveUp'] === true && $kind === 'section' && !$named) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('up-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.up_aria', ['name' => $this->t('forms.row.section_unnamed', ['number' => $data['number']])]) ?>"<?php if ($focus === 'up') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.up') ?></button>
<?php elseif ($row['canMoveUp'] === true && $unnamedStatic) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('up-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.up_aria', ['name' => $this->t($kindKey)]) ?>"<?php if ($focus === 'up') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.up') ?></button>
<?php elseif ($row['canMoveUp'] === true) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('up-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.up_aria', ['name' => $row['name']]) ?>"<?php if ($focus === 'up') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.up') ?></button>
<?php endif; ?>
<?php if ($row['canMoveDown'] === true && $kind === 'section' && !$named) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('down-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.down_aria', ['name' => $this->t('forms.row.section_unnamed', ['number' => $data['number']])]) ?>"<?php if ($focus === 'down') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.down') ?></button>
<?php elseif ($row['canMoveDown'] === true && $unnamedStatic) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('down-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.down_aria', ['name' => $this->t($kindKey)]) ?>"<?php if ($focus === 'down') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.down') ?></button>
<?php elseif ($row['canMoveDown'] === true) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('down-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.down_aria', ['name' => $row['name']]) ?>"<?php if ($focus === 'down') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.down') ?></button>
<?php endif; ?>
<?php if ($kind === 'field') : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('hide-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.hide_aria', ['field' => $row['name']]) ?>"<?php if ($focus === 'last') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.hide') ?></button>
<?php elseif ($kind === 'section' && $named) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('ungroup-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.ungroup_aria', ['name' => $row['name']]) ?>"<?php if ($focus === 'last') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.ungroup') ?></button>
<?php elseif ($kind === 'section') : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('ungroup-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.ungroup_aria', ['name' => $this->t('forms.row.section_unnamed', ['number' => $data['number']])]) ?>"<?php if ($focus === 'last') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.ungroup') ?></button>
<?php elseif (!$unnamedStatic) : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('remove-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.remove_aria', ['name' => $row['name']]) ?>"<?php if ($focus === 'last') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.remove') ?></button>
<?php else : ?>
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('remove-' . $row['path']) ?>" aria-label="<?= $this->t('forms.row.remove_aria', ['name' => $this->t($kindKey)]) ?>"<?php if ($focus === 'last') : ?> autofocus="autofocus"<?php endif; ?>><?= $this->t('forms.row.remove') ?></button>
<?php endif; ?>
</div>
<?php if ($kind === 'section' && count($row['children']) > 0) : ?>
<ol class="form-rows form-row-children">
<?php foreach ($row['children'] as $child) : ?>
<?= $this->partial('forms/row', ['row' => $child, 'number' => $data['number']]) ?>
<?php endforeach; ?>
</ol>
<?php elseif ($kind === 'section') : ?>
<p class="help form-row-note"><?= $this->t('forms.row.empty_section') ?></p>
<?php endif; ?>
</li>
