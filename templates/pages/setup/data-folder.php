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
<li class="step" aria-current="step"><?= $this->t('setup.step.now', ['number' => $step['number']]) ?>
<?php elseif ($step['state'] === 'done') : ?>
<li class="step"><?= $this->t('setup.step.done', ['number' => $step['number']]) ?>
<?php else : ?>
<li class="step"><?= $this->t('setup.step.number', ['number' => $step['number']]) ?>
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
<div class="notice notice-warn" role="alert">
<?php if ($data['outcome'] === 'exposed') : ?>
<h2 class="notice-title"><?= $this->t('setup.data.exposed.title') ?></h2>
<p><?= $this->t('setup.data.exposed.body', ['label' => $data['label']]) ?></p>
<?php elseif ($data['outcome'] === 'inconclusive') : ?>
<h2 class="notice-title"><?= $this->t('setup.data.unclear.title') ?></h2>
<p><?= $this->t('setup.data.unclear.body', ['label' => $data['label']]) ?></p>
<?php elseif ($data['failureKey'] === 'setup.data.not-empty') : ?>
<h2 class="notice-title"><?= $this->t('setup.data.failed.title') ?></h2>
<p><?= $this->t('setup.data.not-empty', ['label' => $data['label']]) ?></p>
<?php else : ?>
<h2 class="notice-title"><?= $this->t('setup.data.failed.title') ?></h2>
<p><?= $this->t('setup.data.no-place') ?></p>
<?php endif; ?>
</div>
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
<div class="form-actions">
<button class="button button-large button-primary" type="submit"><?= $this->t('setup.data.test_again') ?></button>
</div>
</form>
<?php else : ?>
<div class="form-actions">
<a class="button button-primary button-large" href="<?= $this->url('/setup') ?>"><?= $this->t('setup.check.again') ?></a>
</div>
<?php endif; ?>
