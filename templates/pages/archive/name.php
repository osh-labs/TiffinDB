<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import a project archive: the name is taken (or otherwise refused), so
 * the checked archive, kept by the core for one hour, is imported under
 * another name (docs/design/m3/archive.md section 3). The form posts to
 * the kept job; only the Admin who uploaded it can use it.
 *
 * Data: as archive/form, plus `job` (the kept job key) and `fileName`
 * (the uploaded name, plain text, '' when it cannot be shown).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('archive.title') ?></h1>
<p class="lead"><?= $this->t('archive.lead') ?></p>
<form class="form" method="post" action="<?= $this->url('/projects/import/' . $data['job']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="file_name" value="<?= $this->e($data['fileName']) ?>" />
<?php if ($data['fileName'] !== '') : ?>
<p><?= $this->t('archive.checked', ['file' => $data['fileName']]) ?></p>
<?php endif; ?>
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
