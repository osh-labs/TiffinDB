<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The form file upload page (docs/design/m5/source.md section 6). The
 * layout prints the breadcrumb, the flash notice, and the error summary
 * (422) before this. The text of a refused file is never shown.
 *
 * Data: `table` (the table's name, plain text); `base` (the table's path,
 * for the form tools); `action` (the POST address), `cancel` (the
 * editor); `accept` (the file input's suggested extensions); `errors`
 * (the problem lines at the file input, plain text; with any of them the
 * page also says that nothing was changed).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->t('forms.upload.title', ['table' => $data['table']]) ?></h1>
<p class="lead"><?= $this->t('forms.upload.lead', ['table' => $data['table']]) ?></p>
</div>
</div>
<?= $this->partial('forms/tabs', ['base' => $data['base'], 'current' => 'upload']) ?>
<?php if (count($data['errors']) > 0) : ?>
<p><?= $this->t('forms.upload.nothing_changed') ?></p>
<?php endif; ?>
<form class="form" id="form-upload" method="post" action="<?= $this->url($data['action']) ?>" enctype="multipart/form-data">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="file"><?= $this->t('forms.upload.file') ?></label>
<p class="help" id="file-help"><?= $this->t('forms.upload.help') ?></p>
<?php if (count($data['errors']) > 0) : ?>
<p class="error-text" id="file-error"><?php foreach ($data['errors'] as $number => $error) : ?><?php if ($number > 0) : ?> <?php endif; ?><?= $this->partial('field-error', ['text' => $error]) ?><?php endforeach; ?></p>
<input class="input" type="file" id="file" name="file" accept="<?= $this->e($data['accept']) ?>" required="required" aria-describedby="file-help file-error" aria-invalid="true" />
<?php else : ?>
<input class="input" type="file" id="file" name="file" accept="<?= $this->e($data['accept']) ?>" required="required" aria-describedby="file-help" />
<?php endif; ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'forms.upload.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
