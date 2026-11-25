<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * About TiffinDB (docs/design/m7/about.md section 2), for everyone signed
 * in; the server facts for Admins only (the core gives them to Admins
 * only).
 *
 * Data from AboutController: `version` (plain text), `source` (the
 * configured source code address, shown as text: the footer's "Source
 * code" link on this page is the link to it), `components` (list of {id,
 * name, facts}: plain text), `plugins` (list of {name, facts: plain text,
 * enabled: bool}), `admin` (bool), `server` ({php, sqlite} or null).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('about.title') ?></h1>
<p class="lead"><?= $this->t('about.lead') ?></p>
</div>
<dl class="facts">
<div>
<dt><?= $this->t('about.version') ?></dt>
<dd><?= $this->e($data['version']) ?></dd>
</div>
<div>
<dt><?= $this->t('about.licence') ?></dt>
<dd><?= $this->t('about.licence_value') ?> <a href="<?= $this->url('/about/licences/tiffindb') ?>"><?= $this->t('about.licence_link') ?></a></dd>
</div>
<div>
<dt><?= $this->t('about.source') ?></dt>
<dd><?= $this->e($data['source']) ?></dd>
</div>
</dl>
<p><?= $this->t('about.agpl') ?></p>
<h2><?= $this->t('about.components.title') ?></h2>
<p class="help"><?= $this->t('about.components.lead') ?></p>
<dl class="about-list">
<?php foreach ($data['components'] as $component) : ?>
<dt><?= $this->e($component['name']) ?></dt>
<dd><?= $this->e($component['facts']) ?> <a href="<?= $this->url('/about/licences/' . $component['id']) ?>" aria-label="<?= $this->t('about.component.licence_label', ['name' => $component['name']]) ?>"><?= $this->t('about.component.licence_link') ?></a></dd>
<?php endforeach; ?>
</dl>
<h2><?= $this->t('about.plugins.title') ?></h2>
<?php if (count($data['plugins']) === 0) : ?>
<p><?= $this->t('about.plugins.none') ?></p>
<?php else : ?>
<dl class="about-list">
<?php foreach ($data['plugins'] as $plugin) : ?>
<dt><?= $this->e($plugin['name']) ?></dt>
<dd><?= $this->e($plugin['facts']) ?></dd>
<?php if ($data['admin'] === true && $plugin['enabled'] === true) : ?>
<dd><?= $this->t('about.plugin.on') ?></dd>
<?php elseif ($data['admin'] === true) : ?>
<dd><?= $this->t('about.plugin.off') ?></dd>
<?php endif; ?>
<?php endforeach; ?>
</dl>
<?php endif; ?>
<?php if (is_array($data['server'])) : ?>
<h2><?= $this->t('about.server.title') ?></h2>
<dl class="facts">
<div>
<dt><?= $this->t('about.server.php') ?></dt>
<dd><?= $this->e($data['server']['php']) ?></dd>
</div>
<div>
<dt><?= $this->t('about.server.sqlite') ?></dt>
<dd><?= $this->e($data['server']['sqlite']) ?></dd>
</div>
</dl>
<p class="help"><?= $this->t('about.server.note') ?></p>
<?php endif; ?>
