<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The remove confirmation (docs/design/m4/files.md section 4; the M2
 * confirmation pattern). Removing a file cannot be undone (P152).
 *
 * Data: `name` (the file's name), `record` (the record's title), both
 * plain text; `action` (the POST address), `cancel` (the record).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('files.remove.title', ['name' => $data['name']]) ?></h1>
<p><?= $this->t('files.remove.body', ['name' => $data['name'], 'title' => $data['record']]) ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'files.remove.submit', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
