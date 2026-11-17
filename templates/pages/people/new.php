<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Add a person (docs/design/m1/people-and-sharing.md section 3). The
 * layout prints the breadcrumb and the error summary before this page.
 *
 * Data: `errors` (element id => text), `name`, `username`, `role`
 * ('member' or 'admin'), `password` (the suggested or submitted temporary
 * password: a visible field by design, M1 spec part 2 section 4.5 item 4),
 * `projects`: a list by name of {key, name, checked}.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('people.new.title') ?></h1>
<form class="form form-card form-wide" method="post" action="<?= $this->url('/admin/people') ?>">
<?= $this->partial('csrf') ?>
<div class="field">
<label class="label" for="person-name"><?= $this->partial('field-label', ['key' => 'people.field.name', 'optional' => false]) ?></label>
<p class="help" id="person-name-help"><?= $this->t('people.field.name_help') ?></p>
<?php if (is_string($data['errors']['person-name'] ?? null)) : ?>
<p class="error-text" id="person-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['person-name']]) ?></p>
<input class="input" id="person-name" type="text" name="display_name" value="<?= $this->e($data['name']) ?>" autocomplete="off" required="required" aria-describedby="person-name-help person-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="person-name" type="text" name="display_name" value="<?= $this->e($data['name']) ?>" autocomplete="off" required="required" aria-describedby="person-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="person-username"><?= $this->partial('field-label', ['key' => 'people.field.username', 'optional' => false]) ?></label>
<p class="help" id="person-username-help"><?= $this->t('people.field.username_help') ?></p>
<?php if (is_string($data['errors']['person-username'] ?? null)) : ?>
<p class="error-text" id="person-username-error"><?= $this->partial('field-error', ['text' => $data['errors']['person-username']]) ?></p>
<input class="input input-medium" id="person-username" type="text" name="username" value="<?= $this->e($data['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required="required" aria-describedby="person-username-help person-username-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="person-username" type="text" name="username" value="<?= $this->e($data['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required="required" aria-describedby="person-username-help" />
<?php endif; ?>
</div>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('people.field.role') ?></legend>
<?php if (is_string($data['errors']['person-role'] ?? null)) : ?>
<p class="error-text" id="person-role-error"><?= $this->partial('field-error', ['text' => $data['errors']['person-role']]) ?></p>
<?php endif; ?>
<div class="radio-grid">
<?php if ($data['role'] === 'admin') : ?>
<label class="radio-card"><input id="person-role" type="radio" name="role" value="member" /><span><span class="radio-card-title"><?= $this->t('role.member') ?></span><span class="radio-card-hint"><?= $this->t('people.role.member_hint') ?></span></span></label>
<label class="radio-card"><input type="radio" name="role" value="admin" checked="checked" /><span><span class="radio-card-title"><?= $this->t('role.admin') ?></span><span class="radio-card-hint"><?= $this->t('people.role.admin_hint') ?></span></span></label>
<?php else : ?>
<label class="radio-card"><input id="person-role" type="radio" name="role" value="member" checked="checked" /><span><span class="radio-card-title"><?= $this->t('role.member') ?></span><span class="radio-card-hint"><?= $this->t('people.role.member_hint') ?></span></span></label>
<label class="radio-card"><input type="radio" name="role" value="admin" /><span><span class="radio-card-title"><?= $this->t('role.admin') ?></span><span class="radio-card-hint"><?= $this->t('people.role.admin_hint') ?></span></span></label>
<?php endif; ?>
</div>
</fieldset>
<div class="field">
<label class="label" for="person-password"><?= $this->partial('field-label', ['key' => 'people.field.temporary', 'optional' => false]) ?></label>
<p class="help" id="person-password-help"><?= $this->t('people.field.temporary_help') ?></p>
<?php if (is_string($data['errors']['person-password'] ?? null)) : ?>
<p class="error-text" id="person-password-error"><?= $this->partial('field-error', ['text' => $data['errors']['person-password']]) ?></p>
<input class="input input-medium" id="person-password" type="text" name="temporary_password" value="<?= $this->e($data['password']) ?>" autocomplete="off" spellcheck="false" required="required" aria-describedby="person-password-help person-password-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="person-password" type="text" name="temporary_password" value="<?= $this->e($data['password']) ?>" autocomplete="off" spellcheck="false" required="required" aria-describedby="person-password-help" />
<?php endif; ?>
</div>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('people.field.projects') ?></legend>
<p class="help"><?= $this->t('people.field.projects_help') ?></p>
<?php if (count($data['projects']) > 0) : ?>
<ul class="check-list">
<?php foreach ($data['projects'] as $project) : ?>
<?php if ($project['checked'] === true) : ?>
<li><label class="check"><input type="checkbox" name="projects[]" value="<?= $this->e($project['key']) ?>" checked="checked" /><span><?= $this->e($project['name']) ?></span></label></li>
<?php else : ?>
<li><label class="check"><input type="checkbox" name="projects[]" value="<?= $this->e($project['key']) ?>" /><span><?= $this->e($project['name']) ?></span></label></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
<?php else : ?>
<p><?= $this->t('people.field.no_projects') ?></p>
<?php endif; ?>
</fieldset>
<?= $this->partial('form-actions', ['submit' => 'people.new.submit', 'danger' => false, 'cancel' => '/admin/people']) ?>
</form>
