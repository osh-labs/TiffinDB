<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * An empty state (docs/design/m2/states.md section 1): what is missing,
 * what to do next, and optionally one secondary link. No primary button.
 *
 * Data: `title` and `body` (string keys) with `titleParams` and
 * `bodyParams` (their parameters, plain values); optional `link` =
 * {label (string key), labelParams, path, query (array of strings)}.
 */

declare(strict_types=1);

?>
<div class="empty-state">
<p class="empty-state-title"><?= $this->t($data['title'], $data['titleParams'] ?? []) ?></p>
<p><?= $this->t($data['body'], $data['bodyParams'] ?? []) ?></p>
<?php if (is_array($data['link'] ?? null)) : ?>
<?php $link = $data['link']; ?>
<a href="<?= $this->url($link['path'], $link['query'] ?? []) ?>"><?= $this->t($link['label'], $link['labelParams'] ?? []) ?></a>
<?php endif; ?>
</div>
