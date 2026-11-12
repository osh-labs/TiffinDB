<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Top bar of the signed-in shell (M1 spec part 2 section 4.2; P41): the
 * brand link, the main navigation (Projects; People for Admins), and the
 * account navigation (the user chip, a link to the password page, and the
 * Sign out form).
 *
 * The wordmark is live text split for colouring; with the lockup partial
 * it is the one place a template writes the product name itself (ADR 0008
 * decision 5).
 *
 * Data: `shell`, from Shell::toArray().
 */

declare(strict_types=1);

?>
<header class="top-bar">
<a class="brand" href="<?= $this->url('/') ?>" aria-label="<?= $this->t('shell.brand.label') ?>">
<?= $this->partial('mark') ?>
<span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>
</a>
<nav class="top-nav" aria-label="<?= $this->t('shell.nav.main') ?>">
<?php if ($data['shell']['nav'] === 'projects') : ?>
<a class="top-pill" href="<?= $this->url('/projects') ?>" aria-current="page"><?= $this->t('shell.nav.projects') ?></a>
<?php else : ?>
<a class="top-pill" href="<?= $this->url('/projects') ?>"><?= $this->t('shell.nav.projects') ?></a>
<?php endif; ?>
<?php if ($data['shell']['isAdmin'] === true && $data['shell']['nav'] === 'people') : ?>
<a class="top-pill" href="<?= $this->url('/admin/people') ?>" aria-current="page"><?= $this->t('shell.nav.people') ?></a>
<?php elseif ($data['shell']['isAdmin'] === true) : ?>
<a class="top-pill" href="<?= $this->url('/admin/people') ?>"><?= $this->t('shell.nav.people') ?></a>
<?php endif; ?>
</nav>
<nav class="account-nav" aria-label="<?= $this->t('shell.nav.account') ?>">
<?php if ($data['shell']['nav'] === 'account') : ?>
<a class="user-chip" href="<?= $this->url('/account/password') ?>" aria-label="<?= $this->t('shell.account.label', ['name' => $data['shell']['displayName']]) ?>" aria-current="page">
<?php else : ?>
<a class="user-chip" href="<?= $this->url('/account/password') ?>" aria-label="<?= $this->t('shell.account.label', ['name' => $data['shell']['displayName']]) ?>">
<?php endif; ?>
<span class="user-initials" aria-hidden="true"><?= $this->e($data['shell']['initials']) ?></span>
<span class="user-name"><?= $this->e($data['shell']['displayName']) ?></span>
</a>
<form class="sign-out" method="post" action="<?= $this->url('/sign-out') ?>">
<?= $this->partial('csrf') ?>
<button class="top-pill" type="submit"><?= $this->t('shell.sign_out') ?></button>
</form>
</nav>
</header>
