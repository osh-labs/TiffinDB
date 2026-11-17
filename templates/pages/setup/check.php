<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Setup step 1, "Check this server" (docs/design/m1/installer.md sections
 * 2 and 3). Plain shell with a wide main.
 *
 * Data from SetupController: `title`, `wide`, `steps` (list of {number,
 * state: done|now|later}), `checks` (list of {nameKey, status:
 * ok|warning|failed, key, params}, in the installer's order; keys start
 * with `setup.check.`), `canContinue` (bool).
 *
 * The step indicator is written the same way in all three setup pages
 * (shared partials belong to WP8).
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
<section class="section" aria-labelledby="setup-check-title">
<h2 id="setup-check-title" class="section-title"><?= $this->t('setup.check.title') ?></h2>
<p><?= $this->t('setup.check.intro') ?></p>
<div class="table-wrap">
<table class="data-table">
<caption class="visually-hidden"><?= $this->t('setup.check.caption') ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('setup.check.col.check') ?></th>
<th scope="col"><?= $this->t('setup.check.col.result') ?></th>
<th scope="col"><?= $this->t('setup.check.col.meaning') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($data['checks'] as $check) : ?>
<tr>
<th scope="row"><?= $this->t($check['nameKey']) ?></th>
<?php if ($check['status'] === 'failed') : ?>
<td><span class="status-word status-failed"><?= $this->t('setup.result.failed') ?></span></td>
<?php elseif ($check['status'] === 'warning') : ?>
<td><span class="status-word"><?= $this->t('setup.result.warning') ?></span></td>
<?php else : ?>
<td><span class="status-word"><?= $this->t('setup.result.ok') ?></span></td>
<?php endif; ?>
<td><?= $this->t($check['key'], $check['params']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>
<?php if ($data['canContinue'] === true) : ?>
<form class="form" method="post" action="<?= $this->url('/setup/data-folder') ?>">
<?= $this->partial('csrf') ?>
<p><?= $this->t('setup.check.next') ?></p>
<div class="form-actions">
<button class="button button-large button-primary" type="submit"><?= $this->t('setup.check.continue') ?></button>
</div>
</form>
<?php else : ?>
<div class="notice notice-warn" role="alert">
<p><?= $this->t('setup.check.fix') ?></p>
</div>
<div class="form-actions">
<a class="button button-primary" href="<?= $this->url('/setup') ?>"><?= $this->t('setup.check.again') ?></a>
</div>
<?php endif; ?>
