<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Setup step 3, "Create the first Admin" (docs/design/m1/installer.md
 * sections 2 and 5). Plain shell with a wide main; the layout prints the
 * error summary (`problems`) at 422.
 *
 * Data from SetupController: `title`, `wide`, `script` (show-password),
 * `steps` (as in setup/check), `codeConfigured` (bool) and `codeLocation`
 * (the relative path of the setup code file, or its file name when the
 * data folder is set in config/local.php), `values` ({display_name,
 * username}: the submitted values, printed back), `errors` (element id =>
 * plain text), `mismatch` (bool: the two passwords differ), `problems`.
 *
 * The setup code and the passwords are never printed back.
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('setup.title') ?></h1>
<p class="lead"><?= $this->t('setup.lead') ?></p>
</div>
<ol class="steps" aria-label="<?= $this->t('setup.steps.label') ?>">
<?php foreach ($data['steps'] as $step) : ?>
<?php if ($step['state'] === 'now') : ?>
<li class="step" aria-current="step"><span><?= $this->t('setup.step.now', ['number' => $step['number']]) ?></span>
<?php elseif ($step['state'] === 'done') : ?>
<li class="step"><span><?= $this->t('setup.step.done', ['number' => $step['number']]) ?></span>
<?php else : ?>
<li class="step"><span><?= $this->t('setup.step.number', ['number' => $step['number']]) ?></span>
<?php endif; ?>
<?php if ($step['number'] === 1) : ?>
<span class="step-name"><?= $this->t('setup.step.1') ?></span>
<?php elseif ($step['number'] === 2) : ?>
<span class="step-name"><?= $this->t('setup.step.2') ?></span>
<?php else : ?>
<span class="step-name"><?= $this->t('setup.step.3') ?></span>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
<div class="notice" role="status">
<p><?= $this->t('setup.data.ready') ?></p>
</div>
<form class="form form-card form-wide" method="post" action="<?= $this->url('/setup/admin') ?>">
<?= $this->partial('csrf') ?>
<h2 class="section-title"><?= $this->t('setup.admin.title') ?></h2>
<p><?= $this->t('setup.admin.intro') ?></p>
<div class="field">
<label class="label" for="setup-code"><?= $this->t('setup.admin.code') ?></label>
<?php if ($data['codeConfigured'] === true) : ?>
<p class="help" id="setup-code-help"><?= $this->t('setup.code_location.configured', ['file' => $data['codeLocation']]) ?></p>
<?php else : ?>
<p class="help" id="setup-code-help"><?= $this->t('setup.code_location', ['path' => $data['codeLocation']]) ?></p>
<?php endif; ?>
<?php if (is_string($data['errors']['setup-code'] ?? null)) : ?>
<p class="error-text" id="setup-code-error"><?= $this->partial('field-error', ['text' => $data['errors']['setup-code']]) ?></p>
<input class="input input-medium" id="setup-code" type="text" name="setup_code" autocomplete="off" autocapitalize="characters" spellcheck="false" required="required" aria-describedby="setup-code-help setup-code-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="setup-code" type="text" name="setup_code" autocomplete="off" autocapitalize="characters" spellcheck="false" required="required" aria-describedby="setup-code-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="setup-name"><?= $this->t('setup.admin.name') ?></label>
<p class="help" id="setup-name-help"><?= $this->t('setup.admin.name_help') ?></p>
<?php if (is_string($data['errors']['setup-name'] ?? null)) : ?>
<p class="error-text" id="setup-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['setup-name']]) ?></p>
<input class="input" id="setup-name" type="text" name="display_name" value="<?= $this->e($data['values']['display_name']) ?>" autocomplete="name" required="required" aria-describedby="setup-name-help setup-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="setup-name" type="text" name="display_name" value="<?= $this->e($data['values']['display_name']) ?>" autocomplete="name" required="required" aria-describedby="setup-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="setup-username"><?= $this->t('setup.admin.username') ?></label>
<p class="help" id="setup-username-help"><?= $this->t('setup.admin.username_help') ?></p>
<?php if (is_string($data['errors']['setup-username'] ?? null)) : ?>
<p class="error-text" id="setup-username-error"><?= $this->partial('field-error', ['text' => $data['errors']['setup-username']]) ?></p>
<input class="input input-medium" id="setup-username" type="text" name="username" value="<?= $this->e($data['values']['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required="required" aria-describedby="setup-username-help setup-username-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="setup-username" type="text" name="username" value="<?= $this->e($data['values']['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required="required" aria-describedby="setup-username-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="setup-password"><?= $this->t('setup.admin.password') ?></label>
<p class="help" id="setup-password-help"><?= $this->t('setup.admin.password_help') ?></p>
<?php if (is_string($data['errors']['setup-password'] ?? null)) : ?>
<p class="error-text" id="setup-password-error"><?= $this->partial('field-error', ['text' => $data['errors']['setup-password']]) ?></p>
<input class="input" id="setup-password" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="setup-password-help setup-password-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="setup-password" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="setup-password-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="setup-repeat"><?= $this->t('setup.admin.repeat') ?></label>
<?php if ($data['mismatch'] === true) : ?>
<p class="error-text" id="setup-repeat-error"><?= $this->t('setup.admin.mismatch') ?></p>
<input class="input" id="setup-repeat" type="password" name="repeat" autocomplete="new-password" required="required" aria-describedby="setup-repeat-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="setup-repeat" type="password" name="repeat" autocomplete="new-password" required="required" />
<?php endif; ?>
</div>
<?= $this->partial('password-toggle', ['plural' => true]) ?>
<div class="form-actions">
<button class="button button-large button-primary" type="submit"><?= $this->t('setup.admin.submit') ?></button>
</div>
</form>
