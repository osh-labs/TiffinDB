<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Delete a project: the confirmation page (docs/design/m7/project-delete.md
 * section 2), for Admins only (the controller asks the core first). The
 * layout prints the breadcrumb and, after a refusal, the error summary
 * before this. The form posts back `expected`, the record count stated
 * here; when the project holds more by then, nothing is deleted and this
 * page is shown again with `changed` true. The name check is in the core;
 * the button is never disabled.
 *
 * Data: `project` (its name), `changed` and `folderMissing` (bools),
 * `counts` {records, trashed, files, snapshots, members (ints), size
 * (formatted text)}, `expected` (the record count, as a string), `typed`
 * (the name typed before a refusal, plain text), `errors` (element id =>
 * plain text), `exportPath`, `action`, and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('projects.delete.title', ['project' => $data['project']]) ?></h1>
<?php if ($data['changed'] === true) : ?>
<?= $this->partial('notice', ['key' => 'projects.delete.changed', 'params' => [], 'tone' => 'warn']) ?>
<?php endif; ?>
<p class="lead"><?= $this->t('projects.delete.lead') ?></p>
<?php if ($data['folderMissing'] === true) : ?>
<?= $this->partial('notice', ['key' => 'projects.delete.folder_missing', 'params' => [], 'tone' => 'warn']) ?>
<?php else : ?>
<dl class="facts">
<div>
<dt><?= $this->t('projects.delete.records') ?></dt>
<dd><?= $this->t('projects.delete.records_value', ['count' => $data['counts']['records']]) ?></dd>
</div>
<div>
<dt><?= $this->t('projects.delete.trashed') ?></dt>
<dd><?= $this->t('projects.delete.records_value', ['count' => $data['counts']['trashed']]) ?></dd>
</div>
<div>
<dt><?= $this->t('projects.delete.files') ?></dt>
<dd><?= $this->t('projects.delete.files_value', ['count' => $data['counts']['files'], 'size' => $data['counts']['size']]) ?></dd>
</div>
<div>
<dt><?= $this->t('projects.delete.snapshots') ?></dt>
<dd><?= $this->t('projects.delete.snapshots_value', ['count' => $data['counts']['snapshots']]) ?></dd>
</div>
<div>
<dt><?= $this->t('projects.delete.members') ?></dt>
<dd><?= $this->t('projects.delete.members_value', ['count' => $data['counts']['members']]) ?></dd>
</div>
</dl>
<p><?= $this->t('projects.delete.body') ?></p>
<div class="notice" role="status">
<p><?= $this->t('projects.delete.archive') ?> <a href="<?= $this->url($data['exportPath']) ?>"><?= $this->t('projects.delete.archive_link') ?></a></p>
</div>
<?php endif; ?>
<div class="danger-zone">
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="expected" value="<?= $this->e($data['expected']) ?>" />
<div class="field">
<label class="label" for="confirm-name"><?= $this->t('projects.delete.name_label') ?></label>
<p class="help" id="confirm-name-help"><?= $this->t('projects.delete.name_help', ['project' => $data['project']]) ?></p>
<?php if (is_string($data['errors']['confirm-name'] ?? null)) : ?>
<p class="error-text" id="confirm-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['confirm-name']]) ?></p>
<input class="input" id="confirm-name" type="text" name="confirm_name" value="<?= $this->e($data['typed']) ?>" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="confirm-name-help confirm-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="confirm-name" type="text" name="confirm_name" value="<?= $this->e($data['typed']) ?>" autocomplete="off" spellcheck="false" autocapitalize="off" aria-describedby="confirm-name-help" />
<?php endif; ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'projects.delete.submit', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
</div>
