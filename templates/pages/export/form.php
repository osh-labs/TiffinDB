<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Export page (docs/design/m3/export.md section 2). The layout prints the
 * breadcrumb, the flash notice, and the error summary before this.
 *
 * Data: `projectKey`; `formats`: a list of {ref, name (plugin text,
 * plain), scopeKey (string key), checked (bool), settings: list of
 * {input (the input name), label and help (plugin text, plain), kind
 * ('boolean' or 'choice'), value, choices (list of {value, label}),
 * index, format (indexes for ids)}}; `spreadsheetHint` (bool: both the
 * CSV and Excel workbook formats are on, P135); `hasSettings` (bool);
 * `tables`: a list of {key, label (plain)}; `scope` ('table' or
 * 'project'); `table` (the chosen table key); `errors` (element id =>
 * problem text, for f-format, f-scope, f-table); optional `projectSize`
 * (ExportScreen::$projectBytes through Format::fileSize, M4 CF-5): the
 * archive panel shows "About {size}." when it is given.
 *
 * Every option is visible without JavaScript; the server applies only the
 * chosen format's settings.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('export.title') ?></h1>
<p class="lead"><?= $this->t('export.lead') ?></p>
<section class="panel" aria-labelledby="<?= $this->id('x', 'archive') ?>">
<h2 id="<?= $this->id('x', 'archive') ?>"><?= $this->t('export.archive.heading') ?></h2>
<p><?= $this->t('export.archive.body') ?></p>
<?php if (is_string($data['projectSize'] ?? null)) : ?>
<p class="help"><?= $this->t('export.archive.size', ['size' => $data['projectSize']]) ?></p>
<?php endif; ?>
<form class="io-actions" method="post" action="<?= $this->url('/projects/' . $data['projectKey'] . '/export/archive') ?>">
<?= $this->partial('csrf') ?>
<button class="button button-primary button-large" type="submit"><?= $this->t('export.archive.download') ?></button>
</form>
</section>
<?php if (count($data['formats']) === 0) : ?>
<section class="panel" aria-labelledby="<?= $this->id('x', 'formats') ?>">
<h2 id="<?= $this->id('x', 'formats') ?>"><?= $this->t('export.format.legend') ?></h2>
<p class="notice"><?= $this->t('export.format.none') ?></p>
</section>
<?php else : ?>
<form class="form" id="export-form" method="post" action="<?= $this->url('/projects/' . $data['projectKey'] . '/export') ?>">
<?= $this->partial('csrf') ?>
<fieldset class="panel">
<legend><?= $this->t('export.format.legend') ?></legend>
<?php if (is_string($data['errors']['f-format'] ?? null)) : ?>
<p class="error-text" id="<?= $this->id('err', 'format') ?>"><?= $this->partial('field-error', ['text' => $data['errors']['f-format']]) ?></p>
<?php endif; ?>
<?php foreach ($data['formats'] as $i => $format) : ?>
<label class="check"><input type="radio" name="format" value="<?= $this->e($format['ref']) ?>"<?php if ($i === 0) : ?> id="<?= $this->id('f', 'format') ?>"<?php endif; ?><?php if ($i === 0 && is_string($data['errors']['f-format'] ?? null)) : ?> aria-invalid="true" aria-describedby="<?= $this->id('err', 'format') ?>"<?php endif; ?><?php if ($format['checked'] === true) : ?> checked="checked"<?php endif; ?> /><span><?= $this->e($format['name']) ?> <span class="help"><?= $this->t($format['scopeKey']) ?></span></span></label>
<?php endforeach; ?>
<?php if ($data['spreadsheetHint'] === true) : ?>
<p class="help"><?= $this->t('export.format.spreadsheet_hint') ?></p>
<?php endif; ?>
</fieldset>
<fieldset class="panel">
<legend><?= $this->t('export.scope.legend') ?></legend>
<?php if (is_string($data['errors']['f-scope'] ?? null)) : ?>
<p class="error-text" id="<?= $this->id('err', 'scope') ?>"><?= $this->partial('field-error', ['text' => $data['errors']['f-scope']]) ?></p>
<?php endif; ?>
<?php if (is_string($data['errors']['f-table'] ?? null)) : ?>
<p class="error-text" id="<?= $this->id('err', 'table') ?>"><?= $this->partial('field-error', ['text' => $data['errors']['f-table']]) ?></p>
<?php endif; ?>
<?php if (count($data['tables']) > 0) : ?>
<div class="choice-inline">
<label class="check"><input type="radio" name="scope" value="table" id="<?= $this->id('f', 'scope') ?>"<?php if (is_string($data['errors']['f-scope'] ?? null)) : ?> aria-invalid="true" aria-describedby="<?= $this->id('err', 'scope') ?>"<?php endif; ?><?php if ($data['scope'] === 'table') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('export.scope.table') ?></span></label>
<?php if (is_string($data['errors']['f-table'] ?? null)) : ?>
<select class="input" id="<?= $this->id('f', 'table') ?>" name="table" aria-label="<?= $this->t('export.scope.table_label') ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'table') ?>">
<?php else : ?>
<select class="input" id="<?= $this->id('f', 'table') ?>" name="table" aria-label="<?= $this->t('export.scope.table_label') ?>">
<?php endif; ?>
<?php foreach ($data['tables'] as $table) : ?>
<?php if ($table['key'] === $data['table']) : ?>
<option value="<?= $this->e($table['key']) ?>" selected="selected"><?= $this->e($table['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($table['key']) ?>"><?= $this->e($table['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<label class="check"><input type="radio" name="scope" value="project"<?php if ($data['scope'] === 'project') : ?> checked="checked"<?php endif; ?> /><span><?= $this->t('export.scope.project') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="scope" value="project" id="<?= $this->id('f', 'scope') ?>" checked="checked" /><span><?= $this->t('export.scope.project') ?></span></label>
<?php endif; ?>
<p class="help"><?= $this->t('export.scope.help') ?></p>
</fieldset>
<?php if ($data['hasSettings'] === true) : ?>
<fieldset class="panel">
<legend><?= $this->t('export.options.legend') ?></legend>
<?php foreach ($data['formats'] as $format) : ?>
<?php if (count($format['settings']) > 0) : ?>
<div class="field-group">
<p class="label"><?= $this->e($format['name']) ?></p>
<?php foreach ($format['settings'] as $setting) : ?>
<?php if ($setting['kind'] === 'boolean') : ?>
<div class="field">
<?php if ($setting['help'] !== '') : ?>
<label class="check"><input type="checkbox" name="<?= $this->e($setting['input']) ?>" value="1" aria-describedby="<?= $this->id('help', 'set', $setting['format'], $setting['index']) ?>"<?php if ($setting['value'] === '1') : ?> checked="checked"<?php endif; ?> /><span><?= $this->e($setting['label']) ?></span></label>
<p class="help" id="<?= $this->id('help', 'set', $setting['format'], $setting['index']) ?>"><?= $this->e($setting['help']) ?></p>
<?php else : ?>
<label class="check"><input type="checkbox" name="<?= $this->e($setting['input']) ?>" value="1"<?php if ($setting['value'] === '1') : ?> checked="checked"<?php endif; ?> /><span><?= $this->e($setting['label']) ?></span></label>
<?php endif; ?>
</div>
<?php else : ?>
<fieldset class="field">
<legend class="label"><?= $this->e($setting['label']) ?></legend>
<?php if ($setting['help'] !== '') : ?>
<p class="help"><?= $this->e($setting['help']) ?></p>
<?php endif; ?>
<?php foreach ($setting['choices'] as $choice) : ?>
<label class="check"><input type="radio" name="<?= $this->e($setting['input']) ?>" value="<?= $this->e($choice['value']) ?>"<?php if ($choice['value'] === $setting['value']) : ?> checked="checked"<?php endif; ?> /><span><?= $this->e($choice['label']) ?></span></label>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
<div class="io-actions">
<button class="button button-primary button-large" type="submit"><?= $this->t('export.download') ?></button>
<p class="io-note"><?= $this->t('export.note') ?></p>
</div>
</form>
<?php endif; ?>
