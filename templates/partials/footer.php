<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Footer on every page, error pages included (AGPL section 13): licence
 * line and the "Source code" link.
 *
 * Data: `sourceUrl`, the configured link target (never request data);
 * `centered` (bool, default false), true in the plain shell.
 */

declare(strict_types=1);

?>
<?php if (($data['centered'] ?? false) === true) : ?>
<footer class="site-footer site-footer-centered">
<?php else : ?>
<footer class="site-footer">
<?php endif; ?>
<span><?= $this->t('shell.footer.licence') ?></span>
<a href="<?= $this->e($data['sourceUrl']) ?>" rel="noopener noreferrer"><?= $this->t('shell.footer.source') ?></a>
</footer>
