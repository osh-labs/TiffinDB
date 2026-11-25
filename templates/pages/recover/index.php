<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Admin recovery (docs/design/m7/recovery.md section 3), in the plain
 * shell. The layout prints the error summary (`problems`) at 422.
 *
 * Data from RecoveryController: `started` (bool: the "code written" text
 * after starting; the same text whether or not a file was written),
 * `username` (the typed username after a password problem, else ''),
 * `errors` (element id => plain text), `wait` (the formatted waiting time
 * when throttled, else null). The code and the passwords are never
 * printed back.
 */

declare(strict_types=1);

?>
<div class="form-wide">
<h1><?= $this->t('recovery.title') ?></h1>
<p class="lead"><?= $this->t('recovery.lead') ?></p>
</div>
<?php if (is_string($data['wait'])) : ?>
<div class="form-wide">
<?= $this->partial('notice', ['key' => 'recovery.wait', 'params' => ['time' => $data['wait']], 'tone' => 'warn']) ?>
</div>
<?php endif; ?>
<section class="form form-card form-wide" aria-labelledby="recover-step1">
<h2 class="section-title" id="recover-step1"><?= $this->t('recovery.step1.title') ?></h2>
<p><?= $this->t('recovery.step1.body') ?></p>
<?php if ($data['started'] === true) : ?>
<?= $this->partial('notice', ['key' => 'recovery.started', 'params' => [], 'tone' => 'info']) ?>
<?php endif; ?>
<form class="inline-form" method="post" action="<?= $this->url('/recover/start') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'recovery.start', 'secondary' => true, 'size' => 'normal', 'cancel' => null]) ?>
</form>
</section>
<form class="form form-card form-wide" method="post" action="<?= $this->url('/recover') ?>" aria-labelledby="recover-step2">
<?= $this->partial('csrf') ?>
<h2 class="section-title" id="recover-step2"><?= $this->t('recovery.step2.title') ?></h2>
<div class="field">
<label class="label" for="recover-username"><?= $this->partial('field-label', ['key' => 'recovery.username', 'optional' => false]) ?></label>
<input class="input input-medium" id="recover-username" type="text" name="username" value="<?= $this->e($data['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required="required" />
</div>
<div class="field">
<label class="label" for="recover-code"><?= $this->partial('field-label', ['key' => 'recovery.code', 'optional' => false]) ?></label>
<p class="help" id="recover-code-help"><?= $this->t('recovery.code_help') ?></p>
<?php if (is_string($data['errors']['recover-code'] ?? null)) : ?>
<p class="error-text" id="recover-code-error"><?= $this->partial('field-error', ['text' => $data['errors']['recover-code']]) ?></p>
<input class="input input-medium" id="recover-code" type="text" name="code" autocomplete="off" spellcheck="false" autocapitalize="characters" required="required" aria-describedby="recover-code-help recover-code-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="recover-code" type="text" name="code" autocomplete="off" spellcheck="false" autocapitalize="characters" required="required" aria-describedby="recover-code-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="recover-password"><?= $this->partial('field-label', ['key' => 'recovery.password', 'optional' => false]) ?></label>
<p class="help" id="recover-password-help"><?= $this->t('account.password.new_help') ?></p>
<?php if (is_string($data['errors']['recover-password'] ?? null)) : ?>
<p class="error-text" id="recover-password-error"><?= $this->partial('field-error', ['text' => $data['errors']['recover-password']]) ?></p>
<input class="input" id="recover-password" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="recover-password-help recover-password-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="recover-password" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="recover-password-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="recover-again"><?= $this->partial('field-label', ['key' => 'recovery.password_again', 'optional' => false]) ?></label>
<?php if (is_string($data['errors']['recover-again'] ?? null)) : ?>
<p class="error-text" id="recover-again-error"><?= $this->partial('field-error', ['text' => $data['errors']['recover-again']]) ?></p>
<input class="input" id="recover-again" type="password" name="password_again" autocomplete="new-password" required="required" aria-describedby="recover-again-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="recover-again" type="password" name="password_again" autocomplete="new-password" required="required" />
<?php endif; ?>
</div>
<?= $this->partial('password-toggle', ['plural' => true]) ?>
<?= $this->partial('form-actions', ['submit' => 'recovery.submit', 'cancel' => '/sign-in', 'cancelLabel' => 'recovery.back']) ?>
</form>
<p class="help form-wide"><?= $this->t('recovery.other_ways') ?></p>
