<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import step 2, check the fields (docs/design/m3/import.md section 4;
 * mock-up docs/design/model-ui/import.html, with M3 D-15 and D-18). Data:
 * `steps`, `facts` (list of {term (key), value (plain text)}; written
 * here, as the facts partial's markup, so the reading link sits inside
 * the panel as in the mock-up), `action`,
 * `reading`, `cancel`, `existing` (the target is an existing table),
 * `tableName`, `tableNameError`, `tableError`, `tables` (list of {value,
 * label, selected}), `columns` (the mapping table's rows), `warnings`
 * (list of {key, params}), `importCount`. Every file-derived text is
 * plain text, escaped here or by the partials.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.title') ?></h1>
<?= $this->partial('steps', ['label' => 'steps.import.label', 'steps' => $data['steps']]) ?>
<section class="panel" aria-labelledby="<?= $this->id('i', 'file') ?>">
<h2 id="<?= $this->id('i', 'file') ?>"><?= $this->t('import.review.found') ?></h2>
<dl class="facts">
<?php foreach ($data['facts'] as $item) : ?>
<div>
<dt><?= $this->t($item['term']) ?></dt>
<dd><?= $this->e($item['value']) ?></dd>
</div>
<?php endforeach; ?>
</dl>
<div class="link-row">
<a href="<?= $this->url($data['reading']) ?>"><?= $this->t('import.review.change_reading') ?></a>
</div>
</section>
<form class="form" id="<?= $this->id('import', 'review') ?>" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<fieldset class="panel">
<legend><?= $this->t('import.review.target') ?></legend>
<div class="choice-inline">
<?php if ($data['existing'] === true) : ?>
<label class="check"><input type="radio" id="<?= $this->id('f', 'target') ?>" name="target" value="new" /> <span><?= $this->t('import.review.target_new') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" id="<?= $this->id('f', 'target') ?>" name="target" value="new" checked="checked" /> <span><?= $this->t('import.review.target_new') ?></span></label>
<?php endif; ?>
<?php if (is_string($data['tableNameError'])) : ?>
<input class="input" type="text" id="<?= $this->id('f', 'tablename') ?>" name="table_name" value="<?= $this->e($data['tableName']) ?>" maxlength="60" aria-label="<?= $this->t('import.review.table_name_label') ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'tablename') ?>" />
<?php else : ?>
<input class="input" type="text" id="<?= $this->id('f', 'tablename') ?>" name="table_name" value="<?= $this->e($data['tableName']) ?>" maxlength="60" aria-label="<?= $this->t('import.review.table_name_label') ?>" />
<?php endif; ?>
</div>
<?php if (is_string($data['tableNameError'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'tablename') ?>"><?= $this->e($data['tableNameError']) ?></p>
<?php endif; ?>
<?php if (count($data['tables']) > 0) : ?>
<div class="choice-inline">
<?php if ($data['existing'] === true) : ?>
<label class="check"><input type="radio" name="target" value="existing" checked="checked" /> <span><?= $this->t('import.review.target_existing') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="target" value="existing" /> <span><?= $this->t('import.review.target_existing') ?></span></label>
<?php endif; ?>
<?php if (is_string($data['tableError'])) : ?>
<select class="input" id="<?= $this->id('f', 'table') ?>" name="table" aria-label="<?= $this->t('import.review.table_label') ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'table') ?>">
<?php else : ?>
<select class="input" id="<?= $this->id('f', 'table') ?>" name="table" aria-label="<?= $this->t('import.review.table_label') ?>">
<?php endif; ?>
<?php foreach ($data['tables'] as $table) : ?>
<?php if ($table['selected'] === true) : ?>
<option value="<?= $this->e($table['value']) ?>" selected="selected"><?= $this->e($table['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($table['value']) ?>"><?= $this->e($table['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php if (is_string($data['tableError'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'table') ?>"><?= $this->e($data['tableError']) ?></p>
<?php endif; ?>
<p class="help"><?= $this->t('import.review.target_help') ?></p>
<div>
<button class="button" type="submit" name="action" value="recheck"><?= $this->t('import.review.recheck') ?></button>
</div>
<?php endif; ?>
</fieldset>
<section aria-labelledby="<?= $this->id('i', 'fields') ?>">
<h2 id="<?= $this->id('i', 'fields') ?>"><?= $this->t('import.review.fields') ?></h2>
<p class="help"><?= $this->t('import.review.fields_help') ?></p>
<?= $this->partial('import/mapping-table', ['existing' => $data['existing'], 'columns' => $data['columns']]) ?>
</section>
<?php if (count($data['warnings']) > 0) : ?>
<?= $this->partial('warning-panel', ['heading' => 'import.warning.heading', 'headingParams' => ['count' => count($data['warnings'])], 'items' => $data['warnings']]) ?>
<?php endif; ?>
</form>
<div class="io-actions">
<button class="button button-primary button-large" type="submit" form="<?= $this->id('import', 'review') ?>" name="action" value="import"><?= $this->t('import.review.import', ['count' => $data['importCount']]) ?></button>
<form class="inline-form" method="post" action="<?= $this->url($data['cancel']) ?>">
<?= $this->partial('csrf') ?>
<button class="button button-large" type="submit"><?= $this->t('import.start_again') ?></button>
</form>
<p class="io-note"><?= $this->t('import.review.note') ?></p>
</div>
