<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Reading the file, checking the rows, and the save step
 * (docs/design/m3/import.md sections 3 and 6). Data: `steps`, `stage`
 * (`reading`, `preparing`, or `ready`), `progress` (the progress partial's
 * data), `action` (the Continue route), `cancel` (the Start again route).
 *
 * Reading and checking use the shared continue form with `auto` (the
 * import-continue script may submit it). The save step is a form of its
 * own that the script never touches (no data-auto-continue, and the page
 * does not load the script): saving always waits for a press, and only
 * this form sends `step=save`, which the save needs.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.title') ?></h1>
<?= $this->partial('steps', ['label' => 'steps.import.label', 'steps' => $data['steps']]) ?>
<?= $this->partial('progress', $data['progress']) ?>
<?php if ($data['stage'] === 'ready') : ?>
<form class="continue-form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="step" value="save" />
<button class="button button-primary button-large" type="submit"><?= $this->t('import.save') ?></button>
</form>
<p class="help"><?= $this->t('import.preparing.help') ?></p>
<?php elseif ($data['stage'] === 'reading') : ?>
<?= $this->partial('continue-form', ['action' => $data['action'], 'label' => 'import.continue', 'working' => 'import.working', 'auto' => true]) ?>
<p class="help"><?= $this->t('import.reading.help') ?></p>
<form class="inline-form" method="post" action="<?= $this->url($data['cancel']) ?>">
<?= $this->partial('csrf') ?>
<button class="button" type="submit"><?= $this->t('import.start_again') ?></button>
</form>
<?php else : ?>
<?= $this->partial('continue-form', ['action' => $data['action'], 'label' => 'import.continue', 'working' => 'import.working', 'auto' => true]) ?>
<p class="help"><?= $this->t('import.preparing.help') ?></p>
<form class="inline-form" method="post" action="<?= $this->url($data['cancel']) ?>">
<?= $this->partial('csrf') ?>
<button class="button" type="submit"><?= $this->t('import.start_again') ?></button>
</form>
<?php endif; ?>
