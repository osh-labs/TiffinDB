<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Page heading with optional lead and actions (M1 spec part 2 section
 * 4.4; components.md section 1).
 *
 * Data: `title` (plain text, the h1), optional `lead` (plain text),
 * optional `actions`: a list of {label: string key, path: application
 * path, primary: bool, optional icon: 'plus'}. At most one action should
 * be primary (one filled button per screen).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->e($data['title']) ?></h1>
<?php if (is_string($data['lead'] ?? null)) : ?>
<p class="lead"><?= $this->e($data['lead']) ?></p>
<?php endif; ?>
</div>
<?php if (is_array($data['actions'] ?? null) && count($data['actions']) > 0) : ?>
<div class="page-actions">
<?php foreach ($data['actions'] as $action) : ?>
<?php if ($action['primary'] === true) : ?>
<a class="button button-primary" href="<?= $this->url($action['path']) ?>">
<?php else : ?>
<a class="button" href="<?= $this->url($action['path']) ?>">
<?php endif; ?>
<?php if (($action['icon'] ?? null) === 'plus') : ?>
<svg class="button-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M9 2v14M2 9h14" /></svg>
<?php endif; ?>
<span><?= $this->t($action['label']) ?></span>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
