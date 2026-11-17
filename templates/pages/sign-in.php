<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Sign in (docs/design/m1/sign-in-and-projects.md section 2), in the plain
 * shell. Its single error message sits inside the card (M1 spec part 2
 * section 4.5 item 3), so the page passes no `problems`.
 *
 * Data: `signedOut` (bool), `username` (the submitted username, or ''),
 * `error` ('invalid', 'throttled', or null), `throttledText` (the
 * formatted problem.throttled text, or null). The password is never
 * printed back.
 */

declare(strict_types=1);

?>
<?php if ($data['signedOut'] === true) : ?>
<div class="form-narrow">
<?= $this->partial('notice', ['key' => 'notice.signed_out', 'params' => [], 'tone' => 'info']) ?>
</div>
<?php endif; ?>
<form class="form form-card form-narrow" method="post" action="<?= $this->url('/sign-in') ?>">
<?= $this->partial('csrf') ?>
<h1><?= $this->t('signin.title') ?></h1>
<?php if ($data['error'] === 'throttled') : ?>
<div class="notice notice-warn" role="alert" id="signin-error">
<p><?= $this->e($data['throttledText']) ?></p>
</div>
<?php elseif ($data['error'] === 'invalid') : ?>
<div class="notice notice-warn" role="alert" id="signin-error">
<p><?= $this->t('signin.error.invalid') ?></p>
</div>
<?php endif; ?>
<div class="field">
<label class="label" for="signin-username"><?= $this->partial('field-label', ['key' => 'signin.username', 'optional' => false]) ?></label>
<?php if ($data['error'] === null) : ?>
<input class="input" id="signin-username" type="text" name="username" value="<?= $this->e($data['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required="required" />
<?php else : ?>
<input class="input" id="signin-username" type="text" name="username" value="<?= $this->e($data['username']) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required="required" aria-describedby="signin-error" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="signin-password"><?= $this->partial('field-label', ['key' => 'signin.password', 'optional' => false]) ?></label>
<?php if ($data['error'] === null) : ?>
<input class="input" id="signin-password" type="password" name="password" autocomplete="current-password" required="required" />
<?php else : ?>
<input class="input" id="signin-password" type="password" name="password" autocomplete="current-password" required="required" aria-describedby="signin-error" />
<?php endif; ?>
<?= $this->partial('password-toggle', ['plural' => false]) ?>
</div>
<?= $this->partial('form-actions', ['submit' => 'signin.submit', 'block' => true, 'cancel' => null]) ?>
<p class="form-note"><?= $this->t('signin.reset_note') ?></p>
</form>
