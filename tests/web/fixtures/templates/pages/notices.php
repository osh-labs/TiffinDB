<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: one notice with a title, for the notice partial's optional
 * heading level. ShellTest copies it next to the real templates and renders
 * it through View.
 *
 * Data: `tone` (`info` or `warn`), `heading` (passed through to the
 * partial; null to omit it).
 */

declare(strict_types=1);

?>
<?php if (($data['heading'] ?? null) === null) : ?>
<?= $this->partial('notice', ['key' => 'notice.password_changed', 'params' => [], 'tone' => $data['tone'], 'title' => 'error.busy.title']) ?>
<?php else : ?>
<?= $this->partial('notice', ['key' => 'notice.password_changed', 'params' => [], 'tone' => $data['tone'], 'title' => 'error.busy.title', 'heading' => $data['heading']]) ?>
<?php endif; ?>
