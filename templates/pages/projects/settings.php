<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Project settings (docs/design/m1/schema-editor.md section 2). The
 * layout prints the breadcrumb and the error summary before this.
 *
 * Data: `values` {name}, `errors` (element id => plain text), `action`
 * and `cancel` (application paths), `files` {count (int), size (formatted
 * text)}: the stored files of the project (ADR 0018 decision 3).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('schema.settings.title') ?></h1>
<form class="form form-card form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="project-name"><?= $this->t('projects.field.name') ?></label>
<p class="help" id="project-name-help"><?= $this->t('projects.field.name_help') ?></p>
<?php if (is_string($data['errors']['project-name'] ?? null)) : ?>
<p class="error-text" id="project-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['project-name']]) ?></p>
<input class="input" id="project-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="project-name-help project-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="project-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="project-name-help" />
<?php endif; ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'schema.settings.submit', 'danger' => false, 'cancel' => $data['cancel']]) ?>
</form>
<p class="help"><?= $this->t('projects.settings.files', ['count' => $data['files']['count'], 'size' => $data['files']['size']]) ?></p>
