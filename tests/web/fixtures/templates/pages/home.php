<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: start page.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('home.title') ?></h1>
<p><?= $this->t('home.lead') ?></p>
<?php if (is_array($data['preview'] ?? null)): ?>
<nav aria-label="<?= $this->t('home.preview.label') ?>"><ul>
<?php foreach ($data['preview'] as $link): ?>
<li><a href="<?= $this->url($link['path'], $link['query']) ?>"<?php if ($link['current']): ?> aria-current="true"<?php endif; ?>><?= $this->t($link['label']) ?></a></li>
<?php endforeach; ?>
</ul></nav>
<?php endif; ?>
