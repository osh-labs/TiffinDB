<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Stop someone from signing in: the confirmation page
 * (docs/design/m1/people-and-sharing.md section 5). The layout prints the
 * breadcrumb before this page.
 *
 * The actions are written here rather than with the form-actions partial
 * because that partial cannot pass {name} to its submit text; the Cancel
 * link therefore uses this page's own `people.stop.cancel`.
 *
 * Data: `key`, `name`.
 */

declare(strict_types=1);

?>
<?php if (false) : ?>
<?php /* Never rendered: names the breadcrumb key the controller builds, for the strings test. */ ?>
<?= $this->t('people.stop.crumb') ?>
<?php endif; ?>
<h1><?= $this->t('people.stop.title', ['name' => $data['name']]) ?></h1>
<p><?= $this->t('people.stop.body', ['name' => $data['name']]) ?></p>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key'] . '/deactivate') ?>">
<?= $this->partial('csrf') ?>
<div class="form-actions">
<button class="button button-large button-danger" type="submit"><?= $this->t('people.stop.submit', ['name' => $data['name']]) ?></button>
<a class="form-cancel" href="<?= $this->url('/admin/people/' . $data['key']) ?>"><?= $this->t('people.stop.cancel') ?></a>
</div>
</form>
