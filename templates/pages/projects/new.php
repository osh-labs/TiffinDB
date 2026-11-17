<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * New project (docs/design/m1/new-project.md). The layout prints the
 * breadcrumb and the error summary before this.
 *
 * Data: `values` {name, first_table} (as submitted), `errors` (element id
 * => plain text).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('projects.new.title') ?></h1>
<p class="lead"><?= $this->t('projects.new.lead') ?></p>
</div>
<form class="form form-card form-wide" method="post" action="<?= $this->url('/projects') ?>">
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
<div class="field">
<label class="label" for="project-first-table"><?= $this->partial('field-label', ['key' => 'projects.field.first_table', 'optional' => true]) ?></label>
<p class="help" id="project-first-table-help"><?= $this->t('projects.field.first_table_help') ?></p>
<?php if (is_string($data['errors']['project-first-table'] ?? null)) : ?>
<p class="error-text" id="project-first-table-error"><?= $this->partial('field-error', ['text' => $data['errors']['project-first-table']]) ?></p>
<input class="input" id="project-first-table" type="text" name="first_table" value="<?= $this->e($data['values']['first_table']) ?>" autocomplete="off" aria-describedby="project-first-table-help project-first-table-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="project-first-table" type="text" name="first_table" value="<?= $this->e($data['values']['first_table']) ?>" autocomplete="off" aria-describedby="project-first-table-help" />
<?php endif; ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'projects.new.submit', 'danger' => false, 'cancel' => '/projects']) ?>
</form>
