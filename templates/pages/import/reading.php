<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Reading options (docs/design/m3/import.md section 5). Data: `steps`,
 * `action`, `back`, `settings` (list of {n, kind, inputName, label, help,
 * checked, choices (list of {value, label, checked})}), `sheets` (list of
 * {value, label, checked}; empty for a file with one table). Setting
 * labels, help, choice labels, and sheet names come from the plugin or the
 * file: plain text, printed with e().
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.reading_options.title') ?></h1>
<?= $this->partial('steps', ['label' => 'steps.import.label', 'steps' => $data['steps']]) ?>
<p class="lead"><?= $this->t('import.reading_options.lead') ?></p>
<form class="form form-wide" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?php if (count($data['sheets']) > 0) : ?>
<fieldset class="field-group">
<legend class="legend-plain"><?= $this->t('import.reading_options.sheet') ?></legend>
<?php foreach ($data['sheets'] as $sheet) : ?>
<?php if ($sheet['checked'] === true) : ?>
<label class="check"><input type="radio" name="table" value="<?= $this->e($sheet['value']) ?>" checked="checked" /> <span><?= $this->e($sheet['label']) ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="table" value="<?= $this->e($sheet['value']) ?>" /> <span><?= $this->e($sheet['label']) ?></span></label>
<?php endif; ?>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
<?php foreach ($data['settings'] as $setting) : ?>
<?php if ($setting['kind'] === 'choice') : ?>
<fieldset class="field-group">
<legend class="legend-plain"><?= $this->e($setting['label']) ?></legend>
<?php if ($setting['help'] !== '') : ?>
<p class="help"><?= $this->e($setting['help']) ?></p>
<?php endif; ?>
<?php foreach ($setting['choices'] as $choice) : ?>
<?php if ($choice['checked'] === true) : ?>
<label class="check"><input type="radio" name="<?= $this->e($setting['inputName']) ?>" value="<?= $this->e($choice['value']) ?>" checked="checked" /> <span><?= $this->e($choice['label']) ?></span></label>
<?php else : ?>
<label class="check"><input type="radio" name="<?= $this->e($setting['inputName']) ?>" value="<?= $this->e($choice['value']) ?>" /> <span><?= $this->e($choice['label']) ?></span></label>
<?php endif; ?>
<?php endforeach; ?>
</fieldset>
<?php else : ?>
<div class="field-group">
<?php if ($setting['checked'] === true) : ?>
<label class="check"><input type="checkbox" name="<?= $this->e($setting['inputName']) ?>" value="1" checked="checked" /> <span><?= $this->e($setting['label']) ?></span></label>
<?php else : ?>
<label class="check"><input type="checkbox" name="<?= $this->e($setting['inputName']) ?>" value="1" /> <span><?= $this->e($setting['label']) ?></span></label>
<?php endif; ?>
<?php if ($setting['help'] !== '') : ?>
<p class="help"><?= $this->e($setting['help']) ?></p>
<?php endif; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
<div class="io-actions">
<button class="button button-primary button-large" type="submit"><?= $this->t('import.reading_options.apply') ?></button>
<a class="button button-large" href="<?= $this->url($data['back']) ?>"><?= $this->t('import.reading_options.back') ?></a>
</div>
</form>
