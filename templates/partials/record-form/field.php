<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * One field slot of a bound record form (docs/design/m2/record.md section
 * 2.1; M2 spec part 2 sections 4.4 and 5.1). The control itself comes from
 * the shared field-input partial; this partial prints the label (with
 * "(required)" for required fields), the help line, and the error text
 * around it, ids from id('f'|'help'|'err', key) so the error summary and
 * aria-describedby match.
 *
 * - `input`: `div.field` with `label.label`, help, error, control. A
 *   currency label adds the currency name; a duration's help adds how to
 *   type it.
 * - `rating`: `fieldset.field-group` with `legend.legend-plain`, help,
 *   error, then the radios.
 * - `checkbox`: `div.field` with the control (which prints its own
 *   wrapping label), then help and error.
 * - `auto_number`, `relation`, `attachment`: shown, never edited (P105):
 *   `p.label` with id('f', key), then the number, the linked records as
 *   links, or the file names as text.
 *
 * Data: `node`, as RecordController::field() builds it: `control`,
 * `field` (FieldDef), `key`, `label`, `currencyName`, `text`,
 * `submitted`, `required`, `invalid`, `errors` (plain texts), `help`,
 * `hasHelp`, `duration` (null, `minutes`, or `seconds`), `describedBy`,
 * `spanFull`, `labelHidden`, `isNew`, `value`, `links` ({path, title}),
 * `linkedTable`, `files` ({name, type, size}). Every text is plain and is
 * escaped here.
 */

declare(strict_types=1);

?>
<?php $node = $data['node']; ?>
<?php $input = ['field' => $node['field'], 'key' => $node['key'], 'text' => $node['text'], 'submitted' => $node['submitted'], 'required' => $node['required'], 'invalid' => $node['invalid'], 'describedBy' => $node['describedBy'], 'label' => $node['label'], 'currencyName' => $node['currencyName']]; ?>
<?php if ($node['control'] === 'rating') : ?>
<?php if ($node['spanFull'] === true) : ?>
<fieldset class="field-group span-full">
<?php else : ?>
<fieldset class="field-group">
<?php endif; ?>
<legend class="legend-plain"><?= $this->e($node['label']) ?><?php if ($node['required'] === true) : ?> <span class="label-optional"><?= $this->t('input.required') ?></span><?php endif; ?></legend>
<?php elseif ($node['spanFull'] === true) : ?>
<div class="field span-full">
<?php else : ?>
<div class="field">
<?php endif; ?>
<?php if ($node['control'] === 'input') : ?>
<label class="label" for="<?= $this->id('f', $node['key']) ?>"><?php if (is_string($node['currencyName'])) : ?><?= $this->t('input.currency.label', ['label' => $node['label'], 'currency' => $node['currencyName']]) ?><?php else : ?><?= $this->e($node['label']) ?><?php endif; ?><?php if ($node['required'] === true) : ?> <span class="label-optional"><?= $this->t('input.required') ?></span><?php endif; ?></label>
<?php elseif ($node['control'] === 'checkbox') : ?>
<?= $this->partial('field-input', $input) ?>
<?php elseif ($node['control'] === 'auto_number' || $node['control'] === 'relation' || $node['control'] === 'attachment') : ?>
<?php if ($node['labelHidden'] === true) : ?>
<p class="label visually-hidden" id="<?= $this->id('f', $node['key']) ?>"><?= $this->e($node['label']) ?></p>
<?php else : ?>
<p class="label" id="<?= $this->id('f', $node['key']) ?>"><?= $this->e($node['label']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($node['hasHelp'] === true && $node['duration'] === 'seconds' && $node['help'] !== '') : ?>
<p class="help" id="<?= $this->id('help', $node['key']) ?>"><?= $this->e($node['help']) ?> <?= $this->t('input.duration.help_seconds') ?></p>
<?php elseif ($node['hasHelp'] === true && $node['duration'] === 'seconds') : ?>
<p class="help" id="<?= $this->id('help', $node['key']) ?>"><?= $this->t('input.duration.help_seconds') ?></p>
<?php elseif ($node['hasHelp'] === true && $node['duration'] === 'minutes' && $node['help'] !== '') : ?>
<p class="help" id="<?= $this->id('help', $node['key']) ?>"><?= $this->e($node['help']) ?> <?= $this->t('input.duration.help') ?></p>
<?php elseif ($node['hasHelp'] === true && $node['duration'] === 'minutes') : ?>
<p class="help" id="<?= $this->id('help', $node['key']) ?>"><?= $this->t('input.duration.help') ?></p>
<?php elseif ($node['hasHelp'] === true) : ?>
<p class="help" id="<?= $this->id('help', $node['key']) ?>"><?= $this->e($node['help']) ?></p>
<?php endif; ?>
<?php if (count($node['errors']) > 0) : ?>
<p class="error-text" id="<?= $this->id('err', $node['key']) ?>"><?php foreach ($node['errors'] as $i => $text) : ?><?php if ($i > 0) : ?> <?php endif; ?><?= $this->partial('field-error', ['text' => $text]) ?><?php endforeach; ?></p>
<?php endif; ?>
<?php if ($node['control'] === 'input' || $node['control'] === 'rating') : ?>
<?= $this->partial('field-input', $input) ?>
<?php elseif ($node['control'] === 'auto_number' && $node['isNew'] === true) : ?>
<p class="static-value"><?= $this->t('input.auto_number_new') ?></p>
<?php elseif ($node['control'] === 'auto_number') : ?>
<p class="static-value"><?= $this->value($node['field'], $node['value']) ?></p>
<?php elseif ($node['control'] === 'relation' && count($node['links']) > 0) : ?>
<ul class="link-list">
<?php foreach ($node['links'] as $link) : ?>
<li class="link-row"><a href="<?= $this->url($link['path']) ?>"><?= $this->e($link['title']) ?></a></li>
<?php endforeach; ?>
</ul>
<?php elseif ($node['control'] === 'relation') : ?>
<p class="static-value"><?= $this->t('input.links.none') ?></p>
<?php elseif ($node['control'] === 'attachment' && count($node['files']) > 0) : ?>
<?php foreach ($node['files'] as $file) : ?>
<div class="file-row"><span><?= $this->e($file['name']) ?></span> <span class="help"><?= $this->t('input.file.facts', ['type' => $file['type'], 'size' => $file['size']]) ?></span></div>
<?php endforeach; ?>
<?php elseif ($node['control'] === 'attachment') : ?>
<p class="static-value"><?= $this->t('input.files.none') ?></p>
<?php endif; ?>
<?php if ($node['control'] === 'relation') : ?>
<p class="help"><?= $this->t('input.links.help', ['table' => $node['linkedTable']]) ?></p>
<?php endif; ?>
<?php if ($node['control'] === 'rating') : ?>
</fieldset>
<?php else : ?>
</div>
<?php endif; ?>
