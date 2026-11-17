<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: a page that uses every shared partial of M1 spec part 2
 * section 4.4 and the form conventions of section 4.5, the way the screen
 * packages will. ShellTest copies it next to the real templates and
 * renders it through View; TemplateRulesTest checks it follows the rules.
 *
 * It uses only shared string keys (the screen packages own the others),
 * so its labels are arbitrary existing keys.
 *
 * Data: `name` (plain text, printed back), `nameError` (plain text or
 * null), `count` (int), `plural` (bool).
 */

declare(strict_types=1);

?>
<?= $this->partial('page-head', ['title' => $data['name'], 'lead' => $data['name'], 'actions' => [['label' => 'shell.nav.people', 'path' => '/admin/people/new', 'primary' => true, 'icon' => 'plus'], ['label' => 'shell.nav.projects', 'path' => '/projects', 'primary' => false]]]) ?>
<?= $this->partial('notice', ['key' => 'notice.password_changed', 'params' => [], 'tone' => 'info']) ?>
<?= $this->partial('notice', ['key' => 'problem.throttled', 'params' => ['wait' => $data['name']], 'tone' => 'warn', 'title' => 'error.busy.title']) ?>
<p class="lead"><?= $this->count('count.problems', $data['count']) ?></p>
<form class="form form-card form-wide" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="component-name"><?= $this->partial('field-label', ['key' => 'shell.nav.projects', 'optional' => false]) ?></label>
<p class="help" id="component-name-help"><?= $this->t('shell.breadcrumb') ?></p>
<?php if (is_string($data['nameError'] ?? null)) : ?>
<p class="error-text" id="component-name-error"><?= $this->partial('field-error', ['text' => $data['nameError']]) ?></p>
<input class="input" id="component-name" type="text" name="name" value="<?= $this->e($data['name']) ?>" aria-describedby="component-name-help component-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="component-name" type="text" name="name" value="<?= $this->e($data['name']) ?>" aria-describedby="component-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="component-password"><?= $this->partial('field-label', ['key' => 'shell.nav.account', 'optional' => true]) ?></label>
<input class="input input-medium" id="component-password" type="password" name="password" autocomplete="new-password" />
</div>
<div class="field">
<label class="label" for="component-password2"><?= $this->partial('field-label', ['key' => 'shell.nav.main', 'optional' => false]) ?></label>
<input class="input input-medium" id="component-password2" type="password" name="password2" autocomplete="new-password" />
<?= $this->partial('password-toggle', ['plural' => $data['plural']]) ?>
</div>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('shell.nav.people') ?></legend>
<ul class="check-list">
<li><label class="check"><input type="checkbox" name="people[]" value="a" /><span><?= $this->e($data['name']) ?></span></label></li>
<li><label class="check"><input type="checkbox" name="people[]" value="b" checked="checked" /><span><?= $this->e($data['name']) ?></span></label></li>
</ul>
<div class="radio-grid">
<label class="radio-card"><input type="radio" name="kind" value="a" checked="checked" /><span><span class="radio-card-title"><?= $this->t('shell.nav.projects') ?></span><span class="radio-card-hint"><?= $this->t('shell.breadcrumb') ?></span></span></label>
<label class="radio-card"><input type="radio" name="kind" value="b" /><span><span class="radio-card-title"><?= $this->t('shell.nav.people') ?></span><span class="radio-card-hint"><?= $this->t('shell.breadcrumb') ?></span></span></label>
</div>
</fieldset>
<?= $this->partial('form-actions', ['submit' => 'shell.sign_out', 'danger' => false, 'cancel' => '/projects']) ?>
</form>
<form class="form" method="post" action="<?= $this->url('/projects/delete') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'shell.sign_out', 'danger' => true, 'cancel' => null]) ?>
</form>
<ul class="project-grid">
<li class="card project-card">
<h2 class="project-name"><a href="<?= $this->url('/projects') ?>"><?= $this->e($data['name']) ?></a></h2>
<dl class="facts">
<dt><?= $this->t('shell.nav.projects') ?></dt>
<dd><?= $this->e($data['name']) ?></dd>
</dl>
<div class="card-links link-row">
<a class="link-strong" href="<?= $this->url('/projects') ?>"><?= $this->t('shell.nav.projects') ?></a>
<a href="<?= $this->url('/admin/people') ?>"><?= $this->t('shell.nav.people') ?></a>
</div>
</li>
</ul>
<div class="table-wrap">
<table class="data-table">
<caption class="visually-hidden"><?= $this->t('shell.nav.people') ?></caption>
<thead><tr><th scope="col"><?= $this->t('shell.nav.people') ?></th><th scope="col" class="num"><?= $this->t('shell.nav.projects') ?></th></tr></thead>
<tbody>
<tr><th scope="row"><?= $this->e($data['name']) ?></th><td class="num"><?= $this->e($data['count']) ?></td></tr>
<tr><th scope="row"><?= $this->e($data['name']) ?></th><td class="num"><span class="status-word status-failed"><?= $this->t('error.403.title') ?></span></td></tr>
</tbody>
</table>
</div>
<ol class="steps">
<li class="step"><?= $this->t('shell.nav.main') ?><span class="step-name"><?= $this->t('shell.nav.projects') ?></span></li>
<li class="step" aria-current="step"><?= $this->t('shell.nav.main') ?><span class="step-name"><?= $this->t('shell.nav.people') ?></span></li>
</ol>
<p class="notice notice-muted"><?= $this->t('error.403.body') ?></p>
