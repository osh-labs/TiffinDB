<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Breadcrumb (M1 spec part 2 section 4.4). The layout prints it first in
 * `main` when the page data has `breadcrumb`.
 *
 * Data: `items`, a list of {label: plain text, path: application path or
 * null}. Items before the last are links when they have a path; the last
 * item is the current page, never a link. Separators are hidden from
 * assistive technology.
 */

declare(strict_types=1);

?>
<nav class="breadcrumb" aria-label="<?= $this->t('shell.breadcrumb') ?>">
<?php foreach ($data['items'] as $i => $item) : ?>
<?php if ($i > 0) : ?>
<span class="breadcrumb-sep" aria-hidden="true">/</span>
<?php endif; ?>
<?php if ($i === count($data['items']) - 1) : ?>
<span aria-current="page"><?= $this->e($item['label']) ?></span>
<?php elseif (is_string($item['path'] ?? null)) : ?>
<a href="<?= $this->url($item['path']) ?>"><?= $this->e($item['label']) ?></a>
<?php else : ?>
<span><?= $this->e($item['label']) ?></span>
<?php endif; ?>
<?php endforeach; ?>
</nav>
