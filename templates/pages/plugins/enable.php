<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Turn on a plugin that did not come with TiffinDB: the confirmation page
 * (docs/design/m3/plugins.md section 4; the M2 confirmation pattern). The
 * layout prints the breadcrumb before this.
 *
 * Data: `plugin` (name: plain text from its description file; path),
 * `facts` (version, licence, folder) for the facts partial. The form
 * carries `confirm`, which PluginController requires for such a plugin.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('plugins.confirm.title', ['name' => $data['plugin']['name']]) ?></h1>
<p><?= $this->t('plugins.confirm.body', ['name' => $data['plugin']['name']]) ?></p>
<?= $this->partial('facts', ['heading' => 'plugins.details', 'id' => 'details', 'items' => $data['facts']]) ?>
<form class="form" method="post" action="<?= $this->url($data['plugin']['path'] . '/enable') ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="confirm" value="1" />
<?= $this->partial('form-actions', ['submit' => 'plugins.confirm.yes', 'danger' => true, 'cancel' => $data['plugin']['path'], 'cancelLabel' => 'plugins.confirm.no']) ?>
</form>
