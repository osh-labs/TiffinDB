<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The form source view (docs/design/m5/source.md section 2). The layout
 * prints the breadcrumb, the flash notice, and the error summary (422)
 * before this.
 *
 * Data: `table` (the table's name, plain text); `base` (the table's path,
 * for the form tools); `action` (the POST address), `cancel` (the
 * editor); `text` (the text area's content: the stored text, the
 * generated text, or the text as typed; printed only here, through e());
 * `saveBase` (the save token for the hidden `base`); `state` (`stored`,
 * `none`, or `refused`); `storedProblem` (the stored text's problem line,
 * plain text, or null); `errors` (the problem lines at the text area);
 * `report` (null, or the forms/report data after an accepted Check).
 *
 * The text area's content starts after a line feed, because an HTML
 * parser drops one line feed directly after the start tag (M5 spec part 2
 * section 6.6); the text itself is never followed by added white space.
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->t('forms.source.title', ['table' => $data['table']]) ?></h1>
<p class="lead"><?= $this->t('forms.source.lead') ?></p>
</div>
</div>
<?= $this->partial('forms/tabs', ['base' => $data['base'], 'current' => 'source']) ?>
<?php if ($data['state'] === 'none') : ?>
<p class="notice notice-muted"><?= $this->t('forms.source.generated') ?></p>
<?php elseif ($data['state'] === 'refused' && is_string($data['storedProblem'])) : ?>
<div class="notice notice-warn">
<p class="notice-title"><?= $this->t('forms.source.refused_title') ?></p>
<p><?= $this->e($data['storedProblem']) ?></p>
<p><?= $this->t('forms.source.refused') ?></p>
</div>
<?php endif; ?>
<form class="form" id="form-source" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="base" value="<?= $this->e($data['saveBase']) ?>" />
<div class="field">
<label class="label" for="source"><?= $this->t('forms.source.label') ?></label>
<p class="help" id="source-help"><?= $this->t('forms.source.help') ?></p>
<?php if (count($data['errors']) > 0) : ?>
<p class="error-text" id="source-error"><?php foreach ($data['errors'] as $number => $error) : ?><?php if ($number > 0) : ?> <?php endif; ?><?= $this->partial('field-error', ['text' => $error]) ?><?php endforeach; ?></p>
<textarea class="input source-input" id="source" name="text" rows="24" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" dir="ltr" aria-describedby="source-help source-error" aria-invalid="true">
<?= $this->e($data['text']) ?></textarea>
<?php else : ?>
<textarea class="input source-input" id="source" name="text" rows="24" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" dir="ltr" aria-describedby="source-help">
<?= $this->e($data['text']) ?></textarea>
<?php endif; ?>
</div>
<div class="form-actions">
<button class="button button-primary button-large" type="submit" name="do" value="save"><?= $this->t('forms.source.save') ?></button>
<button class="button button-large" type="submit" name="do" value="check"><?= $this->t('forms.source.check') ?></button>
<a class="button button-large" href="<?= $this->url($data['cancel']) ?>"><?= $this->t('forms.source.cancel') ?></a>
</div>
</form>
<?php if (is_array($data['report'])) : ?>
<div class="notice" role="status">
<p><?= $this->t('forms.source.checked') ?></p>
</div>
<?= $this->partial('forms/report', ['report' => $data['report'], 'table' => $data['table'], 'full' => true]) ?>
<?php endif; ?>
