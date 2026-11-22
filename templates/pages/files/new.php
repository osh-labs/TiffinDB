<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The upload page, adding or replacing a file (docs/design/m4/files.md
 * section 2). The layout prints the breadcrumb, the flash notice, and the
 * error summary (422) before this.
 *
 * Data: `record` (the record's title), `field` (the field's label),
 * `item` (the table's item name), all plain text; `replacing` (null, or
 * {id, name} of the file replaced); `action` (the POST address),
 * `cancel` (the record); `imagesOnly` (bool), `accept` (dotted
 * extensions, empty for any file), `max` (the size limit as text, from
 * UploadLimits::effective), `fileProblems` (texts at the file field),
 * `count` (int: files the field holds now), `maxFiles` (formatted).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<?php if (is_array($data['replacing'])) : ?>
<h1><?= $this->t('files.replace.title', ['name' => $data['replacing']['name']]) ?></h1>
<p class="lead"><?= $this->t('files.replace.lead', ['name' => $data['replacing']['name'], 'field' => $data['field']]) ?></p>
<?php else : ?>
<h1><?= $this->t('files.upload.title', ['title' => $data['record']]) ?></h1>
<p class="lead"><?= $this->t('files.upload.lead', ['field' => $data['field']]) ?></p>
<?php endif; ?>
</div>
</div>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>" enctype="multipart/form-data">
<?= $this->partial('csrf') ?>
<?php if (is_array($data['replacing'])) : ?>
<input type="hidden" name="replace" value="<?= $this->e($data['replacing']['id']) ?>" />
<?php endif; ?>
<?php if ($data['imagesOnly'] === true) : ?>
<?= $this->partial('file-field', ['name' => 'file', 'label' => 'files.upload.label', 'help' => 'files.upload.help_images', 'helpParams' => ['max' => $data['max']], 'accept' => $data['accept'], 'problems' => $data['fileProblems']]) ?>
<?php else : ?>
<?= $this->partial('file-field', ['name' => 'file', 'label' => 'files.upload.label', 'help' => 'files.upload.help', 'helpParams' => ['max' => $data['max']], 'accept' => $data['accept'], 'problems' => $data['fileProblems']]) ?>
<?php endif; ?>
<?php if (is_array($data['replacing'])) : ?>
<?= $this->partial('form-actions', ['submit' => 'files.replace.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
<?php else : ?>
<?= $this->partial('form-actions', ['submit' => 'files.upload.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
<?php endif; ?>
</form>
<p class="form-note"><?= $this->t('files.upload.note', ['count' => $data['count'], 'max_files' => $data['maxFiles'], 'item' => $data['item']]) ?></p>
