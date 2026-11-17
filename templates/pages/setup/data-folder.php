<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Setup step 2, "Make the data folder safe", shown only when the data
 * folder is not safe (docs/design/m1/installer.md sections 2 and 4).
 * Status 409. Plain shell with a wide main.
 *
 * Data from SetupController: `title`, `wide`, `steps` (as in
 * setup/check), `outcome` (exposed|inconclusive|failed), `failureKey`
 * (setup.data.no-place|setup.data.not-empty for failed, else null), and
 * `label` (the data folder's label, printed as a value).
 */

declare(strict_types=1);

?>
<div>
<h1><?= $this->t('setup.title') ?></h1>
<p class="lead"><?= $this->t('setup.lead') ?></p>
</div>
<ol class="steps" aria-label="<?= $this->t('setup.steps.label') ?>">
<?php foreach ($data['steps'] as $step) : ?>
<?php if ($step['state'] === 'now') : ?>
<li class="step" aria-current="step"><span><?= $this->t('setup.step.now', ['number' => $step['number']]) ?></span>
<?php elseif ($step['state'] === 'done') : ?>
<li class="step"><span><?= $this->t('setup.step.done', ['number' => $step['number']]) ?></span>
<?php else : ?>
<li class="step"><span><?= $this->t('setup.step.number', ['number' => $step['number']]) ?></span>
<?php endif; ?>
<?php if ($step['number'] === 1) : ?>
<span class="step-name"><?= $this->t('setup.step.1') ?></span>
<?php elseif ($step['number'] === 2) : ?>
<span class="step-name"><?= $this->t('setup.step.2') ?></span>
<?php else : ?>
<span class="step-name"><?= $this->t('setup.step.3') ?></span>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
<?php if ($data['outcome'] === 'exposed') : ?>
<?= $this->partial('notice', ['tone' => 'warn', 'heading' => 2, 'title' => 'setup.data.exposed.title', 'key' => 'setup.data.exposed.body', 'params' => ['label' => $data['label']]]) ?>
<?php elseif ($data['outcome'] === 'inconclusive') : ?>
<?= $this->partial('notice', ['tone' => 'warn', 'heading' => 2, 'title' => 'setup.data.unclear.title', 'key' => 'setup.data.unclear.body', 'params' => ['label' => $data['label']]]) ?>
<?php elseif ($data['failureKey'] === 'setup.data.not-empty') : ?>
<?= $this->partial('notice', ['tone' => 'warn', 'heading' => 2, 'title' => 'setup.data.failed.title', 'key' => 'setup.data.not-empty', 'params' => ['label' => $data['label']]]) ?>
<?php else : ?>
<?= $this->partial('notice', ['tone' => 'warn', 'heading' => 2, 'title' => 'setup.data.failed.title', 'key' => 'setup.data.no-place']) ?>
<?php endif; ?>
<?php if ($data['outcome'] === 'exposed' || $data['outcome'] === 'inconclusive') : ?>
<section class="section" aria-labelledby="setup-fix-title">
<h2 id="setup-fix-title" class="section-title"><?= $this->t('setup.fix.title') ?></h2>
<ol>
<li><?= $this->t('setup.fix.root') ?></li>
<li><?= $this->t('setup.fix.htaccess') ?></li>
<li><?= $this->t('setup.fix.local') ?></li>
</ol>
</section>
<form class="form" method="post" action="<?= $this->url('/setup/data-folder') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'setup.data.test_again']) ?>
</form>
<?php else : ?>
<div class="form-actions">
<a class="button button-primary button-large" href="<?= $this->url('/setup') ?>"><?= $this->t('setup.check.again') ?></a>
</div>
<?php endif; ?>
