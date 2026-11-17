<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A notice (M1 spec part 2 section 4.4): information (`role="status"`) or
 * a warning (`role="alert"`). The layout uses it for flash notices.
 *
 * Data: `key` (string key of the message), `params` (its parameters, plain
 * values), `tone` (`info` or `warn`), and optionally `title` (string key of
 * a bold first line) and `heading` (2 or 3: the title is an `h2` or `h3`,
 * for a notice that heads a section of the page; otherwise a `p`).
 */

declare(strict_types=1);

?>
<?php if ($data['tone'] === 'warn') : ?>
<div class="notice notice-warn" role="alert">
<?php else : ?>
<div class="notice" role="status">
<?php endif; ?>
<?php if (is_string($data['title'] ?? null)) : ?>
<?php if (($data['heading'] ?? null) === 2) : ?>
<h2 class="notice-title"><?= $this->t($data['title']) ?></h2>
<?php elseif (($data['heading'] ?? null) === 3) : ?>
<h3 class="notice-title"><?= $this->t($data['title']) ?></h3>
<?php else : ?>
<p class="notice-title"><?= $this->t($data['title']) ?></p>
<?php endif; ?>
<?php endif; ?>
<p><?= $this->t($data['key'], $data['params'] ?? []) ?></p>
</div>
