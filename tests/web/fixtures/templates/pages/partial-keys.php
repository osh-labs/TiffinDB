<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture for TemplateRulesTest's strings-used scan: string keys
 * passed to shared partials as literals. Never rendered; its keys exist
 * only in the test's own strings array.
 *
 * Counted: literal values under a partial's key parameters, at any depth.
 * Not counted: values under `params` (substituted text), under `cancel`
 * (a path), under a parameter the partial does not treat as a key, or
 * built by concatenation.
 */

declare(strict_types=1);

?>
<form class="form" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'fixture.submit', 'danger' => false, 'cancel' => 'fixture.as_path', 'cancelLabel' => 'fixture.cancel_label']) ?>
</form>
<?= $this->partial('notice', ['title' => 'fixture.notice_title', 'key' => 'fixture.notice', 'params' => ['x' => 'fixture.in_params'], 'tone' => 'info', 'other' => 'fixture.unknown_param']) ?>
<?= $this->partial('page-head', ['title' => $data['title'], 'actions' => [['label' => 'fixture.action', 'path' => '/projects', 'primary' => true]]]) ?>
<?= $this->partial('field-label', ['key' => 'fixture.label', 'optional' => false, 'submit' => 'fixture.wrong_partial']) ?>
<?= $this->partial('field-label', ['key' => 'fixture.built.' . $data['which'], 'optional' => false]) ?>
<p><?= $this->t('fixture.literal') ?></p>
