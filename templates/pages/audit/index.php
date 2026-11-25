<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Activity, the audit log (docs/design/m7/audit.md section 2). Read-only:
 * the filter form is a GET and carries no CSRF field.
 *
 * Data from AuditController: `people`, `projects`, `actions` (lists of
 * {value, text, selected}: the menu options, plain text), `filtered`
 * (bool: a filter is on), `total` (int), `entries` (list of {when: plain
 * text, datetime: a checked timestamp or null, what: plain text, detail:
 * list of {key, value}, plain text}), `pager` (the pager partial's data).
 * Every text from the log is printed with e().
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('audit.title') ?></h1>
<p class="lead"><?= $this->t('audit.lead') ?></p>
</div>
<form class="audit-filters" method="get" action="<?= $this->url('/admin/activity') ?>">
<div class="field">
<label class="label" for="filter-person"><?= $this->t('audit.filter.person') ?></label>
<select class="input" id="filter-person" name="person">
<?php foreach ($data['people'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['value']) ?>" selected="selected"><?= $this->e($option['text']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['value']) ?>"><?= $this->e($option['text']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="field">
<label class="label" for="filter-project"><?= $this->t('audit.filter.project') ?></label>
<select class="input" id="filter-project" name="project">
<?php foreach ($data['projects'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['value']) ?>" selected="selected"><?= $this->e($option['text']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['value']) ?>"><?= $this->e($option['text']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<div class="field">
<label class="label" for="filter-action"><?= $this->t('audit.filter.action') ?></label>
<select class="input" id="filter-action" name="action">
<?php foreach ($data['actions'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['value']) ?>" selected="selected"><?= $this->e($option['text']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['value']) ?>"><?= $this->e($option['text']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<?php if ($data['filtered'] === true) : ?>
<?= $this->partial('form-actions', ['submit' => 'audit.filter.apply', 'secondary' => true, 'size' => 'normal', 'cancel' => '/admin/activity', 'cancelLabel' => 'audit.filter.clear']) ?>
<?php else : ?>
<?= $this->partial('form-actions', ['submit' => 'audit.filter.apply', 'secondary' => true, 'size' => 'normal', 'cancel' => null]) ?>
<?php endif; ?>
</form>
<p class="help"><?= $this->t('audit.count', ['total' => $data['total']]) ?></p>
<?php if (count($data['entries']) === 0) : ?>
<?= $this->partial('empty-state', ['title' => 'audit.empty', 'body' => 'audit.empty_help']) ?>
<?php else : ?>
<ol class="audit-list">
<?php foreach ($data['entries'] as $entry) : ?>
<li class="audit-entry">
<?php if (is_string($entry['datetime'])) : ?>
<p class="audit-when"><time datetime="<?= $this->e($entry['datetime']) ?>"><?= $this->e($entry['when']) ?></time></p>
<?php else : ?>
<p class="audit-when"><?= $this->e($entry['when']) ?></p>
<?php endif; ?>
<p class="audit-what"><?= $this->e($entry['what']) ?></p>
<?php if (count($entry['detail']) > 0) : ?>
<dl class="audit-detail">
<?php foreach ($entry['detail'] as $item) : ?>
<dt><?= $this->e($item['key']) ?></dt>
<dd><?= $this->e($item['value']) ?></dd>
<?php endforeach; ?>
</dl>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
<?= $this->partial('pager', $data['pager']) ?>
<?php endif; ?>
