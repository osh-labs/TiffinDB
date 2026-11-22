<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Delete a collection (docs/design/m4/collections.md section 5; the M2
 * confirmation pattern). Its records are not touched.
 *
 * Data (plain text unless noted): `name`, `items` (the table's name in
 * lower case), `action` (path), `cancel` (path: the editor).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('collections.delete.title', ['name' => $data['name']]) ?></h1>
<p><?= $this->t('collections.delete.body', ['items' => $data['items']]) ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'collections.delete.submit', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
