<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import a project archive: the upload form (docs/design/m3/archive.md
 * section 2). The layout prints the breadcrumb and the error summary
 * before this.
 *
 * Data: `max` (the upload limit as a file size), `name` (the posted
 * name), `maxLength` (the project name limit), `fileProblems` (list of
 * plain texts for the file field), `nameError` (plain text or null).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('archive.title') ?></h1>
<p class="lead"><?= $this->t('archive.lead') ?></p>
<form class="form" method="post" enctype="multipart/form-data" action="<?= $this->url('/projects/import') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('file-field', ['name' => 'file', 'label' => 'archive.file', 'help' => 'archive.file_help', 'helpParams' => ['max' => $data['max']], 'accept' => ['.zip'], 'problems' => $data['fileProblems']]) ?>
<div class="field">
<label class="label" for="<?= $this->id('f', 'name') ?>"><?= $this->partial('field-label', ['key' => 'archive.name', 'optional' => true]) ?></label>
<p class="help" id="<?= $this->id('help', 'name') ?>"><?= $this->t('archive.name_help') ?></p>
<?php if (is_string($data['nameError'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'name') ?>"><?= $this->partial('field-error', ['text' => $data['nameError']]) ?></p>
<input class="input" id="<?= $this->id('f', 'name') ?>" type="text" name="name" value="<?= $this->e($data['name']) ?>" maxlength="<?= $this->e($data['maxLength']) ?>" autocomplete="off" aria-invalid="true" aria-describedby="<?= $this->id('help', 'name') ?> <?= $this->id('err', 'name') ?>" />
<?php else : ?>
<input class="input" id="<?= $this->id('f', 'name') ?>" type="text" name="name" value="<?= $this->e($data['name']) ?>" maxlength="<?= $this->e($data['maxLength']) ?>" autocomplete="off" aria-describedby="<?= $this->id('help', 'name') ?>" />
<?php endif; ?>
</div>
<div class="io-actions">
<button class="button button-primary button-large" type="submit"><?= $this->t('archive.import') ?></button>
<a class="button button-large" href="<?= $this->url('/projects') ?>"><?= $this->t('archive.cancel') ?></a>
</div>
</form>
<p class="help"><?= $this->t('archive.note') ?></p>
<p class="help"><?= $this->t('archive.too_large_note', ['max' => $data['max']]) ?></p>
