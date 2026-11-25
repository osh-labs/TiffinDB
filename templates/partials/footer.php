<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Footer on every page, error pages included (AGPL section 13): licence
 * line, the "About TiffinDB" link when `about` is true (signed-in shell
 * only, M7 screens spec section 5), and the "Source code" link.
 *
 * Data: `sourceUrl`, the configured link target (never request data);
 * `centered` (bool, default false), true in the plain shell; `about`
 * (bool, default false).
 */

declare(strict_types=1);

?>
<?php if (($data['centered'] ?? false) === true) : ?>
<footer class="site-footer site-footer-centered">
<?php else : ?>
<footer class="site-footer">
<?php endif; ?>
<span><?= $this->t('shell.footer.licence') ?></span>
<?php if (($data['about'] ?? false) === true) : ?>
<a href="<?= $this->url('/about') ?>"><?= $this->t('shell.footer.about') ?></a>
<?php endif; ?>
<a href="<?= $this->e($data['sourceUrl']) ?>" rel="noopener noreferrer"><?= $this->t('shell.footer.source') ?></a>
</footer>
