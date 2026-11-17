<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Table fields (docs/design/m1/schema-editor.md section 5): the fields in
 * order with their move buttons, the table settings form, and the way to
 * delete the table. The layout prints the breadcrumb, the flash notice,
 * and the error summary (table settings) before this.
 *
 * Data: `table` {name}, `lead` (plain text), `base` (the table's path),
 * `fields`: a list of {key, name, type (label), links ('one', 'many', or
 * null), required, isTitle, first, last (bools)}, `titleOptions`: a list
 * of {key, name}, `values` {name, item_name, title_field}, `errors`
 * (element id => plain text).
 */

declare(strict_types=1);

?>
<?= $this->partial('page-head', ['title' => $data['table']['name'], 'lead' => $data['lead'], 'actions' => [
    ['label' => 'schema.fields.add', 'path' => $data['base'] . '/fields/new', 'primary' => true],
]]) ?>
<section class="section" aria-labelledby="fields-title">
<h2 class="section-title" id="fields-title"><?= $this->t('schema.fields.title') ?></h2>
<?php if (count($data['fields']) > 0) : ?>
<div class="table-wrap">
<table class="data-table">
<caption class="visually-hidden"><?= $this->t('schema.fields.caption', ['table' => $data['table']['name']]) ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('schema.fields.col.field') ?></th>
<th scope="col"><?= $this->t('schema.fields.col.type') ?></th>
<th scope="col"><?= $this->t('schema.fields.col.required') ?></th>
<th scope="col"><?= $this->t('schema.fields.col.order') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($data['fields'] as $field) : ?>
<tr>
<th scope="row"><a href="<?= $this->url($data['base'] . '/fields/' . $field['key']) ?>"><?= $this->e($field['name']) ?></a><?php if ($field['isTitle'] === true) : ?> <span class="help"><?= $this->t('schema.fields.title_marker') ?></span><?php endif; ?></th>
<?php if ($field['links'] === 'one') : ?>
<td><?= $this->e($field['type']) ?><br /><span class="help"><?= $this->t('schema.links.one') ?></span></td>
<?php elseif ($field['links'] === 'many') : ?>
<td><?= $this->e($field['type']) ?><br /><span class="help"><?= $this->t('schema.links.many') ?></span></td>
<?php else : ?>
<td><?= $this->e($field['type']) ?></td>
<?php endif; ?>
<?php if ($field['required'] === true) : ?>
<td><?= $this->t('schema.fields.required.yes') ?></td>
<?php else : ?>
<td><?= $this->t('schema.fields.required.no') ?></td>
<?php endif; ?>
<td>
<div class="cell-actions">
<?php if ($field['first'] !== true) : ?>
<form method="post" action="<?= $this->url($data['base'] . '/fields/' . $field['key'] . '/move') ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="direction" value="up" />
<button class="button" type="submit" aria-label="<?= $this->t('schema.fields.up_label', ['field' => $field['name']]) ?>"><?= $this->t('schema.fields.up') ?></button>
</form>
<?php endif; ?>
<?php if ($field['last'] !== true) : ?>
<form method="post" action="<?= $this->url($data['base'] . '/fields/' . $field['key'] . '/move') ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="direction" value="down" />
<button class="button" type="submit" aria-label="<?= $this->t('schema.fields.down_label', ['field' => $field['name']]) ?>"><?= $this->t('schema.fields.down') ?></button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else : ?>
<p class="notice"><?= $this->t('schema.fields.empty') ?></p>
<?php endif; ?>
</section>
<form class="form" method="post" action="<?= $this->url($data['base'] . '/settings') ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('schema.table.settings') ?></legend>
<div class="field">
<label class="label" for="settings-name"><?= $this->t('schema.table.name') ?></label>
<p class="help" id="settings-name-help"><?= $this->t('schema.table.name_help') ?></p>
<?php if (is_string($data['errors']['settings-name'] ?? null)) : ?>
<p class="error-text" id="settings-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-name']]) ?></p>
<input class="input" id="settings-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="settings-name-help settings-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="settings-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="settings-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="settings-item"><?= $this->t('schema.table.item') ?></label>
<p class="help" id="settings-item-help"><?= $this->t('schema.table.item_help') ?></p>
<?php if (is_string($data['errors']['settings-item'] ?? null)) : ?>
<p class="error-text" id="settings-item-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-item']]) ?></p>
<input class="input input-medium" id="settings-item" type="text" name="item_name" value="<?= $this->e($data['values']['item_name']) ?>" autocomplete="off" required="required" aria-describedby="settings-item-help settings-item-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="settings-item" type="text" name="item_name" value="<?= $this->e($data['values']['item_name']) ?>" autocomplete="off" required="required" aria-describedby="settings-item-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="settings-title"><?= $this->t('schema.table.title_field') ?></label>
<p class="help" id="settings-title-help"><?= $this->t('schema.table.title_field_help') ?></p>
<?php if (is_string($data['errors']['settings-title'] ?? null)) : ?>
<p class="error-text" id="settings-title-error"><?= $this->partial('field-error', ['text' => $data['errors']['settings-title']]) ?></p>
<select class="input" id="settings-title" name="title_field" aria-describedby="settings-title-help settings-title-error" aria-invalid="true">
<?php else : ?>
<select class="input" id="settings-title" name="title_field" aria-describedby="settings-title-help">
<?php endif; ?>
<?php if ($data['values']['title_field'] === '') : ?>
<option value="" selected="selected"><?= $this->t('schema.table.title_none') ?></option>
<?php else : ?>
<option value=""><?= $this->t('schema.table.title_none') ?></option>
<?php endif; ?>
<?php foreach ($data['titleOptions'] as $option) : ?>
<?php if ($option['key'] === $data['values']['title_field']) : ?>
<option value="<?= $this->e($option['key']) ?>" selected="selected"><?= $this->e($option['name']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['key']) ?>"><?= $this->e($option['name']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="form-actions">
<button class="button" type="submit"><?= $this->t('schema.table.settings_submit') ?></button>
</div>
</fieldset>
</form>
<section class="fieldset-card" aria-labelledby="delete-table-title">
<h2 class="legend" id="delete-table-title"><?= $this->t('schema.table.delete_title') ?></h2>
<p><?= $this->t('schema.table.delete_intro') ?></p>
<p><a class="button button-danger" href="<?= $this->url($data['base'] . '/delete') ?>"><?= $this->t('schema.table.delete') ?></a></p>
</section>
