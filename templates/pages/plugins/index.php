<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Plugins list (docs/design/m3/plugins.md section 2). The layout prints
 * the breadcrumb and the flash notice before this.
 *
 * Data: `import` and `export`, lists of plugins as PluginController
 * describes them: {path, name (plain), status ('enabled', 'disabled',
 * 'unavailable', 'broken'), statusKey (string key), builtIn (bool), facts
 * (plain text or null)}. Names and facts come from plugin description
 * files and are always escaped (P133).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('plugins.title') ?></h1>
<p class="lead"><?= $this->t('plugins.lead') ?></p>
<?php foreach (['import', 'export'] as $kind) : ?>
<section aria-labelledby="<?= $this->id('x', $kind) ?>">
<?php if ($kind === 'import') : ?>
<h2 id="<?= $this->id('x', $kind) ?>"><?= $this->t('plugins.import') ?></h2>
<?php else : ?>
<h2 id="<?= $this->id('x', $kind) ?>"><?= $this->t('plugins.export') ?></h2>
<?php endif; ?>
<?php if (count($data[$kind]) === 0) : ?>
<p><?= $this->t('plugins.none') ?></p>
<?php else : ?>
<ul class="plugin-list">
<?php foreach ($data[$kind] as $plugin) : ?>
<li class="plugin-row">
<div>
<h3><a href="<?= $this->url($plugin['path']) ?>"><?= $this->e($plugin['name']) ?></a></h3>
<?php if (is_string($plugin['facts'])) : ?>
<p class="help"><?= $this->e($plugin['facts']) ?></p>
<?php endif; ?>
<?php if ($plugin['builtIn'] === true) : ?>
<p class="help"><?= $this->t('plugins.shipped') ?></p>
<?php endif; ?>
</div>
<?php if ($plugin['status'] === 'enabled') : ?>
<span class="plugin-status plugin-status-enabled"><?= $this->t($plugin['statusKey']) ?></span>
<?php elseif ($plugin['status'] === 'unavailable') : ?>
<span class="plugin-status plugin-status-unavailable"><?= $this->t($plugin['statusKey']) ?></span>
<?php elseif ($plugin['status'] === 'broken') : ?>
<span class="plugin-status plugin-status-broken"><?= $this->t($plugin['statusKey']) ?></span>
<?php else : ?>
<span class="plugin-status"><?= $this->t($plugin['statusKey']) ?></span>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
<?php endforeach; ?>
<p class="help"><?= $this->t('plugins.install_help') ?></p>
