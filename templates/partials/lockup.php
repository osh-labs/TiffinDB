<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The plain shell's header (M1 spec part 2 sections 4.1 and 4.4): the
 * larger lockup, mark 64 px and wordmark 40 px, linking to the start page.
 *
 * The wordmark is live text split for colouring (brand README); with the
 * top bar it is the one place a template writes the product name itself.
 *
 * Data: none.
 */

declare(strict_types=1);

?>
<header class="lockup-bar">
<a class="brand brand-lockup" href="<?= $this->url('/') ?>" aria-label="<?= $this->t('shell.brand.label') ?>">
<?= $this->partial('mark') ?>
<span class="brand-word"><span class="brand-word-a">Tiffin</span>DB</span>
</a>
</header>
