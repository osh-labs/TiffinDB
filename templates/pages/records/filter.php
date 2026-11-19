<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The filter page (docs/design/m2/list-and-search.md section 3): step 1
 * chooses a filterable field, step 2 the condition on it. Both steps are
 * GET forms; nothing changes. The layout prints the breadcrumb before
 * this.
 *
 * Data (plain text unless noted; paths are application paths; queries are
 * string maps): `tableName`, `listPath`, `filterPath`, `state` (the list
 * state), `step` (0: no filterable field; 1; 2), `hidden` (list of {name,
 * value}). Step 1: `fields` (list of {key, label, selected}). Step 2:
 * `field` {key, name, type}, `empty` (bool: "is empty" is chosen),
 * `choices` (list of {value, selected}), `records` (list of {id, label,
 * selected}), `more` (bool), `ticked` (bool), `from` and `to` (dates or
 * ''), `otherQuery` (the state, for "Choose another field").
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('lists.filter.title', ['table' => $data['tableName']]) ?></h1>
<p class="lead"><?= $this->t('lists.filter.lead') ?></p>
</div>
<?php if ($data['step'] === 0) : ?>
<p class="notice"><?= $this->t('lists.filter.none') ?></p>
<a href="<?= $this->url($data['listPath'], $data['state']) ?>"><?= $this->t('lists.back') ?></a>
<?php elseif ($data['step'] === 1) : ?>
<form class="form form-card form-wide" method="get" action="<?= $this->url($data['filterPath']) ?>">
<?php foreach ($data['hidden'] as $hidden) : ?>
<input type="hidden" name="<?= $this->e($hidden['name']) ?>" value="<?= $this->e($hidden['value']) ?>" />
<?php endforeach; ?>
<div class="field">
<label class="label" for="filter-field"><?= $this->t('lists.filter.field') ?></label>
<select class="input" id="filter-field" name="field">
<?php foreach ($data['fields'] as $field) : ?>
<?php if ($field['selected'] === true) : ?>
<option value="<?= $this->e($field['key']) ?>" selected="selected"><?= $this->e($field['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($field['key']) ?>"><?= $this->e($field['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?= $this->partial('form-actions', ['submit' => 'lists.filter.next', 'cancel' => $data['listPath'], 'cancelQuery' => $data['state']]) ?>
</form>
<?php else : ?>
<form class="form form-card form-wide" method="get" action="<?= $this->url($data['listPath']) ?>">
<?php foreach ($data['hidden'] as $hidden) : ?>
<input type="hidden" name="<?= $this->e($hidden['name']) ?>" value="<?= $this->e($hidden['value']) ?>" />
<?php endforeach; ?>
<?php if ($data['field']['type'] === 'checkbox') : ?>
<input type="hidden" name="op" value="is" />
<?php endif; ?>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('lists.filter.where', ['field' => $data['field']['name']]) ?></legend>
<?php if ($data['field']['type'] === 'choice') : ?>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input id="filter-op-is" type="radio" name="op" value="is" /><span><?= $this->t('lists.filter.op.is') ?></span></label>
<?php else : ?>
<label class="check"><input id="filter-op-is" type="radio" name="op" value="is" checked="checked" /><span><?= $this->t('lists.filter.op.is') ?></span></label>
<?php endif; ?>
<div class="field">
<label class="label" for="filter-value"><?= $this->t('lists.filter.value') ?></label>
<select class="input" id="filter-value" name="v">
<?php foreach ($data['choices'] as $choice) : ?>
<?php if ($choice['selected'] === true) : ?>
<option value="<?= $this->e($choice['value']) ?>" selected="selected"><?= $this->e($choice['value']) ?></option>
<?php else : ?>
<option value="<?= $this->e($choice['value']) ?>"><?= $this->e($choice['value']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input type="radio" name="op" value="empty" checked="checked" /><span><?= $this->t('lists.filter.op.empty') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="op" value="empty" /><span><?= $this->t('lists.filter.op.empty') ?></span></label>
<?php endif; ?>
<?php elseif ($data['field']['type'] === 'checkbox') : ?>
<?php if ($data['ticked'] === true) : ?>
<label class="check"><input type="radio" name="v" value="1" checked="checked" /><span><?= $this->t('lists.filter.op.ticked') ?></span></label>
<label class="check"><input type="radio" name="v" value="0" /><span><?= $this->t('lists.filter.op.not_ticked') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="v" value="1" /><span><?= $this->t('lists.filter.op.ticked') ?></span></label>
<label class="check"><input type="radio" name="v" value="0" checked="checked" /><span><?= $this->t('lists.filter.op.not_ticked') ?></span></label>
<?php endif; ?>
<?php elseif ($data['field']['type'] === 'relation') : ?>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input type="radio" name="op" value="links-to" /><span><?= $this->t('lists.filter.op.links_to') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="op" value="links-to" checked="checked" /><span><?= $this->t('lists.filter.op.links_to') ?></span></label>
<?php endif; ?>
<div class="field">
<label class="label" for="filter-value"><?= $this->t('lists.filter.record') ?></label>
<select class="input" id="filter-value" name="v">
<?php foreach ($data['records'] as $record) : ?>
<?php if ($record['selected'] === true) : ?>
<option value="<?= $this->e($record['id']) ?>" selected="selected"><?= $this->e($record['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($record['id']) ?>"><?= $this->e($record['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
<?php if ($data['more'] === true) : ?>
<p class="help"><?= $this->t('lists.filter.record_limit') ?></p>
<?php endif; ?>
</div>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input type="radio" name="op" value="empty" checked="checked" /><span><?= $this->t('lists.filter.op.no_links') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="op" value="empty" /><span><?= $this->t('lists.filter.op.no_links') ?></span></label>
<?php endif; ?>
<?php else : ?>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input type="radio" name="op" value="between" /><span><?= $this->t('lists.filter.op.between') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="op" value="between" checked="checked" /><span><?= $this->t('lists.filter.op.between') ?></span></label>
<?php endif; ?>
<div class="field">
<label class="label" for="filter-from"><?= $this->t('lists.filter.from') ?></label>
<input class="input input-short" id="filter-from" type="date" name="v" value="<?= $this->e($data['from']) ?>" />
</div>
<div class="field">
<label class="label" for="filter-to"><?= $this->t('lists.filter.to') ?></label>
<input class="input input-short" id="filter-to" type="date" name="v2" value="<?= $this->e($data['to']) ?>" />
</div>
<p class="help"><?= $this->t('lists.filter.range_help') ?></p>
<?php if ($data['empty'] === true) : ?>
<label class="check"><input type="radio" name="op" value="empty" checked="checked" /><span><?= $this->t('lists.filter.op.empty') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="op" value="empty" /><span><?= $this->t('lists.filter.op.empty') ?></span></label>
<?php endif; ?>
<?php endif; ?>
</fieldset>
<?= $this->partial('form-actions', ['submit' => 'lists.filter.submit', 'cancel' => $data['listPath'], 'cancelQuery' => $data['state']]) ?>
</form>
<a href="<?= $this->url($data['filterPath'], $data['otherQuery']) ?>"><?= $this->t('lists.filter.other') ?></a>
<?php endif; ?>
