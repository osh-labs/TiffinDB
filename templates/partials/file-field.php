<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A file input with its label, help, and errors (M3 spec part 2 sections
 * 5.1 and 9.1). The help text states the accepted formats and the size
 * limit (UploadLimits::effective(), through Format::fileSize, as a
 * parameter) before anything is uploaded; the input refers to it, and to
 * the error text when there is one, with aria-describedby. The calling
 * template puts it inside a `form` with method="post",
 * enctype="multipart/form-data", and the csrf partial (section 5.2).
 *
 * Ids: the input is id('f', <id part>), so the error summary can link to
 * it; the help is id('help', <id part>) and the error text id('err', <id
 * part>). The id part is `id` when given, else `name`.
 *
 * Data: `name` (the field name: a lower-case letter, then up to 31 lower-
 * case letters, digits, or underscores, as UploadedFile::FIELD accepts),
 * optional `id` (the id part, 1 to 24 lower-case letters or digits, as
 * Ids::make() accepts; required when `name` holds an underscore or is
 * longer than 24 characters, for example `name` `data_file`, `id`
 * `datafile`; the controller then links its error summary to
 * Ids::make('f', id)), `label` and `help` (string keys), `helpParams`
 * (the help's parameters), `accept` (list of extensions with a leading
 * dot, such as ['.csv', '.txt'], from UploadedFile::acceptList(); empty
 * for no accept attribute), `problems` (list of plain texts; empty when
 * there is none).
 */

declare(strict_types=1);

?>
<?php $accept = ''; ?>
<?php foreach ($data['accept'] as $extension) : ?>
<?php $accept = $accept === '' ? $extension : $accept . ',' . $extension; ?>
<?php endforeach; ?>
<div class="field">
<label class="label" for="<?= $this->id('f', $data['id'] ?? $data['name']) ?>"><?= $this->t($data['label']) ?></label>
<p class="help" id="<?= $this->id('help', $data['id'] ?? $data['name']) ?>"><?= $this->t($data['help'], $data['helpParams'] ?? []) ?></p>
<?php if (count($data['problems']) > 0) : ?>
<p class="error-text" id="<?= $this->id('err', $data['id'] ?? $data['name']) ?>"><?php foreach ($data['problems'] as $number => $problem) : ?><?php if ($number > 0) : ?> <?php endif; ?><?= $this->partial('field-error', ['text' => $problem]) ?><?php endforeach; ?></p>
<?php endif; ?>
<?php if (count($data['problems']) > 0 && $accept !== '') : ?>
<input class="input" type="file" id="<?= $this->id('f', $data['id'] ?? $data['name']) ?>" name="<?= $this->e($data['name']) ?>" accept="<?= $this->e($accept) ?>" required="required" aria-invalid="true" aria-describedby="<?= $this->id('help', $data['id'] ?? $data['name']) ?> <?= $this->id('err', $data['id'] ?? $data['name']) ?>" />
<?php elseif (count($data['problems']) > 0) : ?>
<input class="input" type="file" id="<?= $this->id('f', $data['id'] ?? $data['name']) ?>" name="<?= $this->e($data['name']) ?>" required="required" aria-invalid="true" aria-describedby="<?= $this->id('help', $data['id'] ?? $data['name']) ?> <?= $this->id('err', $data['id'] ?? $data['name']) ?>" />
<?php elseif ($accept !== '') : ?>
<input class="input" type="file" id="<?= $this->id('f', $data['id'] ?? $data['name']) ?>" name="<?= $this->e($data['name']) ?>" accept="<?= $this->e($accept) ?>" required="required" aria-describedby="<?= $this->id('help', $data['id'] ?? $data['name']) ?>" />
<?php else : ?>
<input class="input" type="file" id="<?= $this->id('f', $data['id'] ?? $data['name']) ?>" name="<?= $this->e($data['name']) ?>" required="required" aria-describedby="<?= $this->id('help', $data['id'] ?? $data['name']) ?>" />
<?php endif; ?>
</div>
