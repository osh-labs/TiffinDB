<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One person (docs/design/m1/people-and-sharing.md section 4). The layout
 * prints the breadcrumb, the flash notice, and the error summary before
 * this page; only one of the forms can have failed.
 *
 * Data: `key`, `name`, `username`, `roleText`, `active`, `isAdmin`,
 * `self` (the signed-in Admin), `lastAdmin` (the only active Admin),
 * `details` {name, username, role}, `access`: a list by name of {key,
 * name, checked} (Members only), `password` (a suggested or submitted
 * temporary password: a visible field by design), `errors` (element id =>
 * text).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->e($data['name']) ?></h1>
<?php if ($data['active'] === true) : ?>
<p class="lead"><?= $this->t('people.show.lead', ['username' => $data['username'], 'role' => $data['roleText']]) ?></p>
<?php else : ?>
<p class="lead"><?= $this->t('people.show.lead', ['username' => $data['username'], 'role' => $data['roleText']]) ?> <?= $this->t('people.show.stopped') ?></p>
<?php endif; ?>
</div>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key']) ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('people.details.title') ?></legend>
<div class="field">
<label class="label" for="edit-name"><?= $this->partial('field-label', ['key' => 'people.field.name', 'optional' => false]) ?></label>
<p class="help" id="edit-name-help"><?= $this->t('people.field.name_help') ?></p>
<?php if (is_string($data['errors']['edit-name'] ?? null)) : ?>
<p class="error-text" id="edit-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['edit-name']]) ?></p>
<input class="input" id="edit-name" type="text" name="display_name" value="<?= $this->e($data['details']['name']) ?>" autocomplete="off" required="required" aria-describedby="edit-name-help edit-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="edit-name" type="text" name="display_name" value="<?= $this->e($data['details']['name']) ?>" autocomplete="off" required="required" aria-describedby="edit-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="edit-username"><?= $this->partial('field-label', ['key' => 'people.field.username', 'optional' => false]) ?></label>
<p class="help" id="edit-username-help"><?= $this->t('people.field.username_help') ?></p>
<?php if (is_string($data['errors']['edit-username'] ?? null)) : ?>
<p class="error-text" id="edit-username-error"><?= $this->partial('field-error', ['text' => $data['errors']['edit-username']]) ?></p>
<input class="input input-medium" id="edit-username" type="text" name="username" value="<?= $this->e($data['details']['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required="required" aria-describedby="edit-username-help edit-username-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="edit-username" type="text" name="username" value="<?= $this->e($data['details']['username']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required="required" aria-describedby="edit-username-help" />
<?php endif; ?>
</div>
<fieldset class="field">
<legend class="legend-plain"><?= $this->t('people.field.role') ?></legend>
<?php if (is_string($data['errors']['edit-role'] ?? null)) : ?>
<p class="error-text" id="edit-role-error"><?= $this->partial('field-error', ['text' => $data['errors']['edit-role']]) ?></p>
<?php endif; ?>
<div class="radio-grid">
<?php if ($data['details']['role'] === 'admin') : ?>
<label class="radio-card"><input id="edit-role" type="radio" name="role" value="member" /><span><span class="radio-card-title"><?= $this->t('role.member') ?></span><span class="radio-card-hint"><?= $this->t('people.role.member_hint') ?></span></span></label>
<label class="radio-card"><input type="radio" name="role" value="admin" checked="checked" /><span><span class="radio-card-title"><?= $this->t('role.admin') ?></span><span class="radio-card-hint"><?= $this->t('people.role.admin_hint') ?></span></span></label>
<?php else : ?>
<label class="radio-card"><input id="edit-role" type="radio" name="role" value="member" checked="checked" /><span><span class="radio-card-title"><?= $this->t('role.member') ?></span><span class="radio-card-hint"><?= $this->t('people.role.member_hint') ?></span></span></label>
<label class="radio-card"><input type="radio" name="role" value="admin" /><span><span class="radio-card-title"><?= $this->t('role.admin') ?></span><span class="radio-card-hint"><?= $this->t('people.role.admin_hint') ?></span></span></label>
<?php endif; ?>
</div>
</fieldset>
<div class="form-actions">
<button class="button button-primary" type="submit"><?= $this->t('people.details.submit') ?></button>
</div>
</fieldset>
</form>
<?php if ($data['isAdmin'] === true) : ?>
<section class="fieldset-card">
<h2 class="legend"><?= $this->t('people.access.title') ?></h2>
<p><?= $this->t('people.access.admin') ?></p>
</section>
<?php else : ?>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key'] . '/projects') ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('people.access.title') ?></legend>
<?php if (count($data['access']) > 0) : ?>
<ul class="check-list">
<?php foreach ($data['access'] as $project) : ?>
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
<div class="form-actions">
<button class="button" type="submit"><?= $this->t('people.access.submit') ?></button>
</div>
</fieldset>
</form>
<?php endif; ?>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key'] . '/password') ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('people.password.title') ?></legend>
<p><?= $this->t('people.password.intro', ['name' => $data['name']]) ?></p>
<?php if ($data['self'] === true) : ?>
<p><?= $this->t('people.password.self') ?></p>
<?php endif; ?>
<div class="field">
<label class="label" for="reset-password"><?= $this->partial('field-label', ['key' => 'people.field.temporary', 'optional' => false]) ?></label>
<p class="help" id="reset-password-help"><?= $this->t('people.field.temporary_help') ?></p>
<?php if (is_string($data['errors']['reset-password'] ?? null)) : ?>
<p class="error-text" id="reset-password-error"><?= $this->partial('field-error', ['text' => $data['errors']['reset-password']]) ?></p>
<input class="input input-medium" id="reset-password" type="text" name="temporary_password" value="<?= $this->e($data['password']) ?>" autocomplete="off" spellcheck="false" aria-describedby="reset-password-help reset-password-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-medium" id="reset-password" type="text" name="temporary_password" value="<?= $this->e($data['password']) ?>" autocomplete="off" spellcheck="false" aria-describedby="reset-password-help" />
<?php endif; ?>
</div>
<div class="form-actions">
<button class="button" type="submit"><?= $this->t('people.password.submit') ?></button>
</div>
</fieldset>
</form>
<section class="fieldset-card">
<h2 class="legend"><?= $this->t('people.signin.title') ?></h2>
<?php if ($data['active'] === true) : ?>
<p><?= $this->t('people.signin.active', ['name' => $data['name']]) ?></p>
<?php if ($data['self'] === true) : ?>
<p class="help"><?= $this->t('people.signin.self') ?></p>
<?php elseif ($data['lastAdmin'] === true) : ?>
<p class="help"><?= $this->t('people.signin.last_admin', ['name' => $data['name']]) ?></p>
<?php else : ?>
<a class="button button-danger" href="<?= $this->url('/admin/people/' . $data['key'] . '/deactivate') ?>"><?= $this->t('people.signin.stop', ['name' => $data['name']]) ?></a>
<?php endif; ?>
<?php else : ?>
<p><?= $this->t('people.signin.inactive', ['name' => $data['name']]) ?></p>
<form class="form" method="post" action="<?= $this->url('/admin/people/' . $data['key'] . '/reactivate') ?>">
<?= $this->partial('csrf') ?>
<button class="button" type="submit"><?= $this->t('people.signin.restart', ['name' => $data['name']]) ?></button>
</form>
<?php endif; ?>
</section>
