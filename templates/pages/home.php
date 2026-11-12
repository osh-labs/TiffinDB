<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The M0 start page (M0 spec section 9.3): title, one sentence, and, when
 * the appearance preview is on, one link per theme.
 *
 * Data: `preview`, null or a list of {path, query, label, current}, where
 * `label` is a string key and `current` marks the theme shown.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('home.title') ?></h1>
<p class="lead"><?= $this->t('home.lead') ?></p>
<?php if (is_array($data['preview'] ?? null)) : ?>
<nav class="preview" aria-labelledby="preview-title">
<h2 id="preview-title" class="preview-title"><?= $this->t('home.preview.label') ?></h2>
<ul class="preview-list">
<?php foreach ($data['preview'] as $link) : ?>
<li><a class="preview-link" href="<?= $this->url($link['path'], $link['query']) ?>"<?php if ($link['current'] === true) : ?> aria-current="true"<?php endif; ?>><?= $this->t($link['label']) ?></a></li>
<?php endforeach; ?>
</ul>
</nav>
<?php endif; ?>
