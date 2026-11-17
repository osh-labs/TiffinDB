<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Stop someone from signing in: the confirmation page
 * (docs/design/m1/people-and-sharing.md section 5). The layout prints the
 * breadcrumb before this page.
 *
 * Data: `key`, `name`.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('people.stop.title', ['name' => $data['name']]) ?></h1>
<p><?= $this->t('people.stop.body', ['name' => $data['name']]) ?></p>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key'] . '/deactivate') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'people.stop.submit', 'submitParams' => ['name' => $data['name']], 'danger' => true, 'cancel' => '/admin/people/' . $data['key']]) ?>
</form>
