<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import step 1, choose a file (docs/design/m3/import.md section 2).
 * Data: `steps`, `action`, `cancel`, `accept` (dotted extensions), `max`
 * (the size limit as text), `fileProblems` (texts), `showFormats`,
 * `autoChecked`, `formats` (list of {value, name, extensions, checked};
 * plugin names are plain text).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.title') ?></h1>
<?= $this->partial('steps', ['label' => 'steps.import.label', 'steps' => $data['steps']]) ?>
<p class="lead"><?= $this->t('import.start.lead') ?></p>
<form class="form form-wide" method="post" action="<?= $this->url($data['action']) ?>" enctype="multipart/form-data">
<?= $this->partial('csrf') ?>
<?= $this->partial('file-field', ['name' => 'file', 'label' => 'import.start.file', 'help' => 'import.start.file_help', 'helpParams' => ['max' => $data['max']], 'accept' => $data['accept'], 'problems' => $data['fileProblems']]) ?>
<?php if ($data['showFormats'] === true) : ?>
<fieldset class="panel">
<legend><?= $this->t('import.start.format') ?></legend>
<?php if ($data['autoChecked'] === true) : ?>
<label class="check"><input type="radio" name="format" value="auto" checked="checked" /> <span><?= $this->t('import.start.format_auto') ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="format" value="auto" /> <span><?= $this->t('import.start.format_auto') ?></span></label>
<?php endif; ?>
<?php foreach ($data['formats'] as $format) : ?>
<?php if ($format['checked'] === true) : ?>
<label class="check"><input type="radio" name="format" value="<?= $this->e($format['value']) ?>" checked="checked" /> <span><?= $this->e($format['name']) ?> <span class="help"><?= $this->e($format['extensions']) ?></span></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="format" value="<?= $this->e($format['value']) ?>" /> <span><?= $this->e($format['name']) ?> <span class="help"><?= $this->e($format['extensions']) ?></span></span></label>
<?php endif; ?>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
<div class="io-actions">
<button class="button button-primary button-large" type="submit"><?= $this->t('import.start.continue') ?></button>
<a class="button button-large" href="<?= $this->url($data['cancel']) ?>"><?= $this->t('import.start.cancel') ?></a>
</div>
</form>
<p class="help"><?= $this->t('import.start.note') ?></p>
