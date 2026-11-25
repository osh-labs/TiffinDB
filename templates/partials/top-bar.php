<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Top bar of the signed-in shell (M1 spec part 2 section 4.2; M2 spec
 * part 2 section 3.1; M3 spec part 2 section 4; M7 screens spec section
 * 5): the brand link, the main navigation (Projects; People, Plugins, and
 * Activity for Admins), the project search when the page has `search`, and
 * the account navigation (the Display link to the reading and display
 * settings, the user chip, a link to the password page, and the Sign out
 * form).
 *
 * The wordmark is live text split for colouring; with the lockup partial
 * it is the one place a template writes the product name itself (ADR 0008
 * decision 5).
 *
 * Data: `shell`, from Shell::toArray(); optional `search` (array for the
 * top-search partial, or null).
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
<?php if ($data['shell']['isAdmin'] === true && $data['shell']['nav'] === 'plugins') : ?>
<a class="top-pill" href="<?= $this->url('/admin/plugins') ?>" aria-current="page"><?= $this->t('shell.nav.plugins') ?></a>
<?php elseif ($data['shell']['isAdmin'] === true) : ?>
<a class="top-pill" href="<?= $this->url('/admin/plugins') ?>"><?= $this->t('shell.nav.plugins') ?></a>
<?php endif; ?>
<?php if ($data['shell']['isAdmin'] === true && $data['shell']['nav'] === 'activity') : ?>
<a class="top-pill" href="<?= $this->url('/admin/activity') ?>" aria-current="page"><?= $this->t('shell.nav.activity') ?></a>
<?php elseif ($data['shell']['isAdmin'] === true) : ?>
<a class="top-pill" href="<?= $this->url('/admin/activity') ?>"><?= $this->t('shell.nav.activity') ?></a>
<?php endif; ?>
</nav>
<?php if (is_array($data['search'] ?? null)) : ?>
<?= $this->partial('top-search', ['search' => $data['search']]) ?>
<?php endif; ?>
<nav class="account-nav" aria-label="<?= $this->t('shell.nav.account') ?>">
<?php if ($data['shell']['nav'] === 'settings') : ?>
<a class="top-pill top-pill-display" href="<?= $this->url('/settings') ?>" aria-current="page">
<?php else : ?>
<a class="top-pill top-pill-display" href="<?= $this->url('/settings') ?>">
<?php endif; ?>
<svg class="top-pill-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8" /><path class="top-pill-icon-half" d="M10 2a8 8 0 0 1 0 16z" /></svg>
<span><?= $this->t('shell.nav.display') ?></span>
</a>
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
