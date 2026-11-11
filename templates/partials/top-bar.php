<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Top bar (M0 spec section 9.1): the brand link only. The Display link,
 * user chip, and "Sign out" arrive with accounts in M1.
 *
 * The wordmark is live text split for colouring; it is the one place a
 * template writes the product name itself (ADR 0008 decision 5).
 */

declare(strict_types=1);

?>
<header class="top-bar">
<a class="brand" href="<?= $this->url('/') ?>" aria-label="<?= $this->t('shell.brand.label') ?>">
<?= $this->partial('mark') ?>
<span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>
</a>
</header>
