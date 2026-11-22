<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: renders the shared M5 form partials (M5 spec part 2
 * section 6.1) the way the editor and source pages of M5-WP5 and WP6
 * will: the form tools navigation, the editor rows inside the one editor
 * form (with the `editor-help` line their inputs name), and the report.
 * ComponentsTest copies it next to the real templates and renders it
 * through View; TemplateRulesTest checks it follows the rules.
 *
 * Data (each part optional): `tabs` (forms/tabs data), `rows` (list of
 * {row, number}: forms/row data), `report` (forms/report data).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('app.name') ?></h1>
<?php if (is_array($data['tabs'] ?? null)) : ?>
<?= $this->partial('forms/tabs', $data['tabs']) ?>
<?php endif; ?>
<p class="help" id="<?= $this->id('editor', 'help') ?>"><?= $this->t('app.name') ?></p>
<?php if (is_array($data['rows'] ?? null)) : ?>
<form id="form-editor" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<ol class="form-rows" aria-label="<?= $this->t('app.name') ?>">
<?php foreach ($data['rows'] as $item) : ?>
<?= $this->partial('forms/row', $item) ?>
<?php endforeach; ?>
</ol>
</form>
<?php endif; ?>
<?php if (is_array($data['report'] ?? null)) : ?>
<?= $this->partial('forms/report', $data['report']) ?>
<?php endif; ?>
