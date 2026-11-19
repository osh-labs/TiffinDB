<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A plugin's page (docs/design/m3/plugins.md section 3). The layout
 * prints the breadcrumb, the flash notice, and the error summary before
 * this.
 *
 * Data: `plugin` as PluginController describes it (name, description,
 * missing: plain text from the plugin or the server; status, statusKey,
 * builtIn, reasonKey, path), `facts` for the facts partial. Turning on and
 * off are POST forms; turning on a plugin that did not come with
 * TiffinDB goes through the confirmation page first.
 */

declare(strict_types=1);

?>
<div class="page-head">
<h1><?= $this->e($data['plugin']['name']) ?></h1>
<?php if ($data['plugin']['status'] === 'enabled') : ?>
<span class="plugin-status plugin-status-enabled"><?= $this->t($data['plugin']['statusKey']) ?></span>
<?php elseif ($data['plugin']['status'] === 'unavailable') : ?>
<span class="plugin-status plugin-status-unavailable"><?= $this->t($data['plugin']['statusKey']) ?></span>
<?php elseif ($data['plugin']['status'] === 'broken') : ?>
<span class="plugin-status plugin-status-broken"><?= $this->t($data['plugin']['statusKey']) ?></span>
<?php else : ?>
<span class="plugin-status"><?= $this->t($data['plugin']['statusKey']) ?></span>
<?php endif; ?>
</div>
<?= $this->partial('facts', ['heading' => 'plugins.details', 'id' => 'details', 'items' => $data['facts']]) ?>
<?php if ($data['plugin']['description'] !== '') : ?>
<p><?= $this->e($data['plugin']['description']) ?></p>
<?php endif; ?>
<?php if ($data['plugin']['status'] === 'enabled') : ?>
<form class="io-actions" method="post" action="<?= $this->url($data['plugin']['path'] . '/disable') ?>">
<?= $this->partial('csrf') ?>
<button class="button button-large" type="submit"><?= $this->t('plugins.disable') ?></button>
<p class="help io-note"><?= $this->t('plugins.disable_help') ?></p>
</form>
<?php elseif ($data['plugin']['status'] === 'disabled' && $data['plugin']['builtIn'] === true) : ?>
<form class="io-actions" method="post" action="<?= $this->url($data['plugin']['path'] . '/enable') ?>">
<?= $this->partial('csrf') ?>
<button class="button button-primary button-large" type="submit"><?= $this->t('plugins.enable') ?></button>
</form>
<?php elseif ($data['plugin']['status'] === 'disabled') : ?>
<div class="io-actions">
<a class="button button-primary button-large" href="<?= $this->url($data['plugin']['path'] . '/enable') ?>"><?= $this->t('plugins.enable') ?></a>
</div>
<?php elseif ($data['plugin']['status'] === 'unavailable') : ?>
<p class="notice"><?= $this->t('plugins.unavailable_body', ['extensions' => $data['plugin']['missing']]) ?></p>
<?php else : ?>
<p class="notice"><?= $this->t('plugins.broken_body') ?></p>
<?php if (is_string($data['plugin']['reasonKey'])) : ?>
<p class="help"><?= $this->t($data['plugin']['reasonKey']) ?></p>
<?php endif; ?>
<?php endif; ?>
<p><a href="<?= $this->url('/admin/plugins') ?>"><?= $this->t('plugins.back') ?></a></p>
