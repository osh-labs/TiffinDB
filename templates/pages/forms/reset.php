<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Back to the standard form: the confirmation page (docs/design/m5/reset.md
 * section 2; M2 confirmation pattern). The layout prints the breadcrumb
 * and the flash notice first. When the table already has the standard
 * form, the page says so and offers only the way back to the editor.
 *
 * Data: `base` (the table's path), `table` {name}, `isStandard` (bool).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('forms.reset.title') ?></h1>
<?= $this->partial('forms/tabs', ['base' => $data['base'], 'current' => 'reset']) ?>
<?php if ($data['isStandard'] === true) : ?>
<p class="notice notice-muted"><?= $this->t('forms.reset.already', ['table' => $data['table']['name']]) ?></p>
<p><a class="button button-large" href="<?= $this->url($data['base'] . '/form') ?>"><?= $this->t('forms.reset.back') ?></a></p>
<?php else : ?>
<p><?= $this->t('forms.reset.body', ['table' => $data['table']['name']]) ?></p>
<p><?= $this->t('forms.reset.download_intro') ?></p>
<p><a class="button" href="<?= $this->url($data['base'] . '/form/download') ?>"><?= $this->t('forms.reset.download') ?></a></p>
<form class="form" method="post" action="<?= $this->url($data['base'] . '/form/reset') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'forms.reset.submit', 'danger' => true, 'cancel' => $data['base'] . '/form']) ?>
</form>
<?php endif; ?>
