<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Change your password (docs/design/m1/sign-in-and-projects.md section 4).
 * The layout prints the error summary before this page.
 *
 * Data: `forced` (bool: the person must change their password; no Cancel),
 * `errors` (element id => text for account-current and account-new),
 * `mismatch` (bool: the two new passwords differ). No password is ever
 * printed back.
 */

declare(strict_types=1);

?>
<?php if ($data['forced'] === true) : ?>
<?= $this->partial('notice', ['key' => 'account.password.forced', 'params' => [], 'tone' => 'info']) ?>
<?php endif; ?>
<div>
<h1><?= $this->t('account.password.title') ?></h1>
<p class="lead"><?= $this->t('account.password.lead') ?></p>
</div>
<form class="form form-card form-narrow" method="post" action="<?= $this->url('/account/password') ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="account-current"><?= $this->partial('field-label', ['key' => 'account.password.current', 'optional' => false]) ?></label>
<?php if (is_string($data['errors']['account-current'] ?? null)) : ?>
<p class="error-text" id="account-current-error"><?= $this->partial('field-error', ['text' => $data['errors']['account-current']]) ?></p>
<input class="input" id="account-current" type="password" name="current" autocomplete="current-password" required="required" aria-describedby="account-current-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="account-current" type="password" name="current" autocomplete="current-password" required="required" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="account-new"><?= $this->partial('field-label', ['key' => 'account.password.new', 'optional' => false]) ?></label>
<p class="help" id="account-new-help"><?= $this->t('account.password.new_help') ?></p>
<?php if (is_string($data['errors']['account-new'] ?? null)) : ?>
<p class="error-text" id="account-new-error"><?= $this->partial('field-error', ['text' => $data['errors']['account-new']]) ?></p>
<input class="input" id="account-new" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="account-new-help account-new-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="account-new" type="password" name="password" autocomplete="new-password" required="required" aria-describedby="account-new-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="account-repeat"><?= $this->partial('field-label', ['key' => 'account.password.repeat', 'optional' => false]) ?></label>
<?php if ($data['mismatch'] === true) : ?>
<p class="error-text" id="account-repeat-error"><?= $this->t('account.password.mismatch') ?></p>
<input class="input" id="account-repeat" type="password" name="repeat" autocomplete="new-password" required="required" aria-describedby="account-repeat-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="account-repeat" type="password" name="repeat" autocomplete="new-password" required="required" />
<?php endif; ?>
</div>
<?= $this->partial('password-toggle', ['plural' => true]) ?>
<?php if ($data['forced'] === true) : ?>
<?= $this->partial('form-actions', ['submit' => 'account.password.submit', 'danger' => false, 'cancel' => null]) ?>
<?php else : ?>
<?= $this->partial('form-actions', ['submit' => 'account.password.submit', 'danger' => false, 'cancel' => '/projects']) ?>
<?php endif; ?>
</form>
