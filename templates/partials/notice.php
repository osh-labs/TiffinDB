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
 * a bold first line).
 */

declare(strict_types=1);

?>
<?php if ($data['tone'] === 'warn') : ?>
<div class="notice notice-warn" role="alert">
<?php else : ?>
<div class="notice" role="status">
<?php endif; ?>
<?php if (is_string($data['title'] ?? null)) : ?>
<p class="notice-title"><?= $this->t($data['title']) ?></p>
<?php endif; ?>
<p><?= $this->t($data['key'], $data['params'] ?? []) ?></p>
</div>
