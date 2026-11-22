<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The collection editor, new or existing (docs/design/m4/collections.md
 * section 3; mock-up docs/design/model-ui/collection.html). The layout
 * prints the breadcrumb, the flash notice, and the error summary first.
 *
 * One form; its submit buttons carry `action`. The first submit button is
 * a hidden Preview (`tabindex="-1"`), so Enter in a text box previews
 * (P163). "Add a rule", each row's "Remove", and the visible Preview
 * redisplay the form with the posted values; only "Save collection"
 * stores. No script submits the form (WCAG 3.2.2; P181).
 *
 * Data (plain text unless noted; paths are application paths): `heading`,
 * `action` (the form's address), `name`, `nameError` (or null), `items`
 * (the table's name in lower case), `matchAny` (bool), `rows` (list of
 * rule-row data), `canAddRule` (bool), `sorts` (list of {token, label,
 * selected}), `cancel` (path), `deletePath` (or null for a new
 * collection), `preview` (preview data).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->e($data['heading']) ?></h1>
<p class="lead"><?= $this->t('collections.lead') ?></p>
</div>
<form id="collection-form" class="collection-form form-card" method="post" action="<?= $this->url($data['action']) ?>" data-collection-editor="1">
<?= $this->partial('csrf') ?>
<button class="default-action" type="submit" name="action" value="preview" tabindex="-1"><?= $this->t('collections.preview') ?></button>
<div class="field">
<label class="label" for="<?= $this->id('f', 'name') ?>"><?= $this->t('collections.name.label') ?></label>
<?php if (is_string($data['nameError'])) : ?>
<p class="error-text" id="<?= $this->id('f', 'name', 'error') ?>"><?= $this->partial('field-error', ['text' => $data['nameError']]) ?></p>
<input class="input input-medium" id="<?= $this->id('f', 'name') ?>" type="text" name="name" value="<?= $this->e($data['name']) ?>" maxlength="60" autocomplete="off" aria-describedby="<?= $this->id('f', 'name', 'error') ?>" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="<?= $this->id('f', 'name') ?>" type="text" name="name" value="<?= $this->e($data['name']) ?>" maxlength="60" autocomplete="off" />
<?php endif; ?>
</div>
<fieldset class="field-group">
<legend class="legend-plain"><?= $this->t('collections.match.legend', ['items' => $data['items']]) ?></legend>
<div class="match-row">
<?php if ($data['matchAny'] === true) : ?>
<label class="check"><input type="radio" name="match" value="all" /><span><?= $this->t('collections.match.all') ?></span></label>
<label class="check"><input type="radio" name="match" value="any" checked="checked" /><span><?= $this->t('collections.match.any') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="match" value="all" checked="checked" /><span><?= $this->t('collections.match.all') ?></span></label>
<label class="check"><input type="radio" name="match" value="any" /><span><?= $this->t('collections.match.any') ?></span></label>
<?php endif; ?>
</div>
</fieldset>
<div class="rule-list">
<?php foreach ($data['rows'] as $row) : ?>
<?= $this->partial('collections/rule-row', ['row' => $row]) ?>
<?php endforeach; ?>
<?php if ($data['canAddRule'] === true) : ?>
<div>
<button class="button button-strong" type="submit" name="action" value="add"><?= $this->t('collections.add_rule') ?></button>
</div>
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="<?= $this->id('f', 'sort') ?>"><?= $this->t('collections.sort.label') ?></label>
<select class="input input-medium" id="<?= $this->id('f', 'sort') ?>" name="sort">
<option value=""><?= $this->t('collections.sort.default') ?></option>
<?php foreach ($data['sorts'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['token']) ?>" selected="selected"><?= $this->e($option['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['token']) ?>"><?= $this->e($option['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="collection-actions">
<button class="button button-primary button-large" type="submit" name="action" value="save"><?= $this->t('collections.save') ?></button>
<button class="button button-large" type="submit" name="action" value="preview" data-preview="1"><?= $this->t('collections.preview') ?></button>
<a class="button button-large" href="<?= $this->url($data['cancel']) ?>"><?= $this->t('collections.cancel') ?></a>
</div>
</form>
<?php if (is_string($data['deletePath'])) : ?>
<p><a href="<?= $this->url($data['deletePath']) ?>"><?= $this->t('collections.delete.link') ?></a></p>
<?php endif; ?>
<?= $this->partial('collections/preview', $data['preview']) ?>
