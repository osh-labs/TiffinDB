<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The review step's mapping table (docs/design/m3/import.md section 4
 * item 4.2; docs/design/m3/components.md section 5). Data: `existing`
 * (true: the "Goes into" column; false: field name and type) and
 * `columns`, each {n, heading, include, includeName, nameName, typeName,
 * fieldName, name, types, fields (lists of {value, label, selected}),
 * noField, first, leftOut, nameError, typeError, fieldError}. Headings,
 * names, and first values come from the file: plain text, escaped here,
 * also inside every aria-label.
 */

declare(strict_types=1);

?>
<div class="table-scroll">
<table class="mapping-table">
<caption class="visually-hidden"><?= $this->t('import.review.caption') ?></caption>
<thead>
<?php if ($data['existing'] === true) : ?>
<tr><th scope="col"><?= $this->t('import.col.include') ?></th><th scope="col"><?= $this->t('import.col.column') ?></th><th scope="col"><?= $this->t('import.col.field') ?></th><th scope="col"><?= $this->t('import.col.first') ?></th></tr>
<?php else : ?>
<tr><th scope="col"><?= $this->t('import.col.include') ?></th><th scope="col"><?= $this->t('import.col.column') ?></th><th scope="col"><?= $this->t('import.col.name') ?></th><th scope="col"><?= $this->t('import.col.type') ?></th><th scope="col"><?= $this->t('import.col.first') ?></th></tr>
<?php endif; ?>
</thead>
<tbody>
<?php foreach ($data['columns'] as $column) : ?>
<tr>
<?php if ($column['include'] === true) : ?>
<td><input type="checkbox" name="<?= $this->e($column['includeName']) ?>" value="1" checked="checked" aria-label="<?= $this->t('import.review.include_label', ['column' => $column['heading']]) ?>" /></td>
<th scope="row"><?= $this->e($column['heading']) ?></th>
<?php else : ?>
<td><input type="checkbox" name="<?= $this->e($column['includeName']) ?>" value="1" aria-label="<?= $this->t('import.review.include_label', ['column' => $column['heading']]) ?>" /></td>
<th class="mapping-left-out" scope="row"><?= $this->e($column['heading']) ?></th>
<?php endif; ?>
<?php if ($data['existing'] === true) : ?>
<td>
<?php if (is_string($column['fieldError'])) : ?>
<select class="input" id="<?= $this->id('f', 'field', $column['n']) ?>" name="<?= $this->e($column['fieldName']) ?>" aria-label="<?= $this->t('import.review.field_label', ['column' => $column['heading']]) ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'field', $column['n']) ?>">
<?php else : ?>
<select class="input" id="<?= $this->id('f', 'field', $column['n']) ?>" name="<?= $this->e($column['fieldName']) ?>" aria-label="<?= $this->t('import.review.field_label', ['column' => $column['heading']]) ?>">
<?php endif; ?>
<?php if ($column['noField'] === true) : ?>
<option value="" selected="selected"><?= $this->t('import.review.field_none') ?></option>
<?php else : ?>
<option value=""><?= $this->t('import.review.field_none') ?></option>
<?php endif; ?>
<?php foreach ($column['fields'] as $choice) : ?>
<?php if ($choice['selected'] === true) : ?>
<option value="<?= $this->e($choice['value']) ?>" selected="selected"><?= $this->e($choice['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($choice['value']) ?>"><?= $this->e($choice['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php if (is_string($column['fieldError'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'field', $column['n']) ?>"><?= $this->e($column['fieldError']) ?></p>
<?php endif; ?>
</td>
<?php else : ?>
<td>
<?php if (is_string($column['nameError'])) : ?>
<input class="input" type="text" id="<?= $this->id('f', 'name', $column['n']) ?>" name="<?= $this->e($column['nameName']) ?>" value="<?= $this->e($column['name']) ?>" maxlength="60" aria-label="<?= $this->t('import.review.name_label', ['column' => $column['heading']]) ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'name', $column['n']) ?>" />
<p class="error-text" id="<?= $this->id('err', 'name', $column['n']) ?>"><?= $this->e($column['nameError']) ?></p>
<?php else : ?>
<input class="input" type="text" id="<?= $this->id('f', 'name', $column['n']) ?>" name="<?= $this->e($column['nameName']) ?>" value="<?= $this->e($column['name']) ?>" maxlength="60" aria-label="<?= $this->t('import.review.name_label', ['column' => $column['heading']]) ?>" />
<?php endif; ?>
</td>
<td>
<?php if (is_string($column['typeError'])) : ?>
<select class="input" id="<?= $this->id('f', 'type', $column['n']) ?>" name="<?= $this->e($column['typeName']) ?>" aria-label="<?= $this->t('import.review.type_label', ['column' => $column['heading']]) ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'type', $column['n']) ?>">
<?php else : ?>
<select class="input" id="<?= $this->id('f', 'type', $column['n']) ?>" name="<?= $this->e($column['typeName']) ?>" aria-label="<?= $this->t('import.review.type_label', ['column' => $column['heading']]) ?>">
<?php endif; ?>
<?php foreach ($column['types'] as $choice) : ?>
<?php if ($choice['selected'] === true) : ?>
<option value="<?= $this->e($choice['value']) ?>" selected="selected"><?= $this->e($choice['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($choice['value']) ?>"><?= $this->e($choice['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php if (is_string($column['typeError'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'type', $column['n']) ?>"><?= $this->e($column['typeError']) ?></p>
<?php endif; ?>
</td>
<?php endif; ?>
<?php if ($column['leftOut'] === true) : ?>
<td class="mapping-first"><?= $this->e($column['first']) ?><?= $this->t('import.review.left_out') ?></td>
<?php else : ?>
<td class="mapping-first"><?= $this->e($column['first']) ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
