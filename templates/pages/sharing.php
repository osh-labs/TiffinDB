<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Sharing a project (docs/design/m1/people-and-sharing.md section 6). The
 * layout prints the breadcrumb before this page.
 *
 * Data: `key`, `project` (its name), `members`: a list by name of the
 * active Members {key, name, username, granted}, `inactiveGranted` (how
 * many Members who cannot sign in hold a grant).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('sharing.title', ['project' => $data['project']]) ?></h1>
<p class="lead"><?= $this->t('sharing.lead') ?></p>
</div>
<?php if (count($data['members']) > 0) : ?>
<form class="form form-wide" method="post" action="<?= $this->url('/projects/' . $data['key'] . '/sharing') ?>">
<?= $this->partial('csrf') ?>
<fieldset class="fieldset-card">
<legend class="legend"><?= $this->t('sharing.members') ?></legend>
<ul class="check-list">
<?php foreach ($data['members'] as $member) : ?>
<?php if ($member['granted'] === true) : ?>
<li><label class="check"><input type="checkbox" name="members[]" value="<?= $this->e($member['key']) ?>" checked="checked" /><span><?= $this->e($member['name']) ?><span class="check-hint"><?= $this->e($member['username']) ?></span></span></label></li>
<?php else : ?>
<li><label class="check"><input type="checkbox" name="members[]" value="<?= $this->e($member['key']) ?>" /><span><?= $this->e($member['name']) ?><span class="check-hint"><?= $this->e($member['username']) ?></span></span></label></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
</fieldset>
<?php if ($data['inactiveGranted'] === 1) : ?>
<p class="help"><?= $this->t('sharing.inactive.one') ?></p>
<?php elseif ($data['inactiveGranted'] > 1) : ?>
<p class="help"><?= $this->t('sharing.inactive.many', ['count' => $data['inactiveGranted']]) ?></p>
<?php endif; ?>
<?= $this->partial('form-actions', ['submit' => 'sharing.submit', 'danger' => false, 'cancel' => '/projects']) ?>
</form>
<?php else : ?>
<div class="notice">
<p><?= $this->t('sharing.none') ?></p>
<a class="button button-primary" href="<?= $this->url('/admin/people/new') ?>"><?= $this->t('people.add') ?></a>
</div>
<?php endif; ?>
