<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Empty the trash: the confirmation page (docs/design/m4/trash.md section
 * 4), for Admins only (the controller asks the core first). The layout
 * prints the breadcrumb before this. The form posts back `expected`, the
 * record count stated here; when more records wait by then, nothing is
 * deleted and this page is shown again with `changed` true.
 *
 * Data: `changed` (bool), `total` and `files` (ints), `tables`: a list of
 * {name, count (int)}, `expected` (the record count, as a string),
 * `action` and `cancel` (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('trash.empty.title') ?></h1>
<?php if ($data['changed'] === true) : ?>
<?= $this->partial('notice', ['key' => 'trash.empty.changed', 'params' => [], 'tone' => 'info']) ?>
<?php endif; ?>
<p><?= $this->t('trash.empty.body', ['count' => $data['total']]) ?></p>
<ul>
<?php foreach ($data['tables'] as $table) : ?>
<li><?= $this->t('trash.empty.table', ['table' => $table['name'], 'count' => $table['count']]) ?></li>
<?php endforeach; ?>
</ul>
<?php if ($data['files'] > 0) : ?>
<p><?= $this->t('trash.empty.files', ['files' => $data['files']]) ?></p>
<?php endif; ?>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="expected" value="<?= $this->e($data['expected']) ?>" />
<?= $this->partial('form-actions', ['submit' => 'trash.empty.submit', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
