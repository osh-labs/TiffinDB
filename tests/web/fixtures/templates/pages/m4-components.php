<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: renders the shared M4 partials (M4 spec part 2 section
 * 6.1) the way the record page of M4-WP6 will: each inside a field slot
 * whose `p.label` has id('f', key); the relation and file slots inside
 * the record form, and each unlink form after it, never nested.
 * ComponentsTest copies it next to the real templates and renders it
 * through View; TemplateRulesTest checks it follows the rules.
 *
 * Data (each part optional): `one` (link-control data), `many` (link-rows
 * data), `files` (file-rows data), `leave` (bool: the leave note alone).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('app.name') ?></h1>
<form id="record-form" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<?php if (is_array($data['one'] ?? null)) : ?>
<div class="field">
<p class="label" id="<?= $this->id('f', $data['one']['key']) ?>"><?= $this->t('sidebar.tables') ?></p>
<?= $this->partial('link-control', $data['one']) ?>
</div>
<?php endif; ?>
<?php if (is_array($data['many'] ?? null)) : ?>
<div class="field">
<p class="label" id="<?= $this->id('f', $data['many']['key']) ?>"><?= $this->t('sidebar.collections') ?></p>
<?= $this->partial('link-rows', $data['many']) ?>
</div>
<?php endif; ?>
<?php if (is_array($data['files'] ?? null)) : ?>
<div class="field">
<p class="label" id="<?= $this->id('f', $data['files']['key']) ?>"><?= $this->t('sidebar.data') ?></p>
<?= $this->partial('file-rows', $data['files']) ?>
</div>
<?php endif; ?>
<?php if (($data['leave'] ?? null) === true) : ?>
<?= $this->partial('leave-note') ?>
<?php endif; ?>
<button class="button button-primary" type="submit"><?= $this->t('app.name') ?></button>
</form>
<?php if (is_array($data['many'] ?? null) && $data['many']['unlink'] === true && count($data['many']['rows']) > 0) : ?>
<form class="unlink-form" id="<?= $this->id('unlink', $data['many']['key']) ?>" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="back" value="record" />
</form>
<?php endif; ?>
