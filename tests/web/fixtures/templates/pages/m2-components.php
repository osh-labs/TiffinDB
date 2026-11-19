<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: renders the shared M2 partials (M2 spec part 2 section
 * 4.1) the way the screen packages will. ComponentsTest copies it next to
 * the real templates and renders it through View; TemplateRulesTest checks
 * it follows the rules. A record form slot is printed as
 * docs/design/m2/record.md section 2.1 describes (label, help, errors,
 * then the field-input partial), so the controls can be checked in place.
 *
 * Data (each part optional): `fields` = list of {input (the field-input
 * data), help (plain text or null), error (plain text or null)}; `values`
 * = list of {field, value, mode}; `table` (record-table data); `pager`
 * (pager data); `empty` (empty-state data); `inline` (form-inline nodes).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('app.name') ?></h1>
<?php if (is_array($data['fields'] ?? null)) : ?>
<form class="record-form" id="record-form" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<?php foreach ($data['fields'] as $slot) : ?>
<?php $facts = $this->fieldFacts($slot['input']['field']); ?>
<?php if ($facts['type'] === 'rating') : ?>
<fieldset class="field-group">
<legend class="legend-plain"><?= $this->e($slot['input']['label']) ?></legend>
<?php if (is_string($slot['help'])) : ?>
<p class="help" id="<?= $this->id('help', $slot['input']['key']) ?>"><?= $this->e($slot['help']) ?></p>
<?php endif; ?>
<?php if (is_string($slot['error'])) : ?>
<p class="error-text" id="<?= $this->id('err', $slot['input']['key']) ?>"><?= $this->e($slot['error']) ?></p>
<?php endif; ?>
<?= $this->partial('field-input', $slot['input']) ?>
</fieldset>
<?php elseif ($facts['type'] === 'checkbox') : ?>
<div class="field">
<?= $this->partial('field-input', $slot['input']) ?>
<?php if (is_string($slot['help'])) : ?>
<p class="help" id="<?= $this->id('help', $slot['input']['key']) ?>"><?= $this->e($slot['help']) ?></p>
<?php endif; ?>
<?php if (is_string($slot['error'])) : ?>
<p class="error-text" id="<?= $this->id('err', $slot['input']['key']) ?>"><?= $this->e($slot['error']) ?></p>
<?php endif; ?>
</div>
<?php else : ?>
<div class="field">
<?php if ($facts['type'] === 'currency') : ?>
<label class="label" for="<?= $this->id('f', $slot['input']['key']) ?>"><?= $this->t('input.currency.label', ['label' => $slot['input']['label'], 'currency' => $slot['input']['currencyName'] ?? $facts['code']]) ?></label>
<?php else : ?>
<label class="label" for="<?= $this->id('f', $slot['input']['key']) ?>"><?= $this->e($slot['input']['label']) ?></label>
<?php endif; ?>
<?php if (is_string($slot['help']) && $facts['type'] === 'duration' && $facts['seconds'] === true) : ?>
<p class="help" id="<?= $this->id('help', $slot['input']['key']) ?>"><?= $this->e($slot['help']) ?> <?= $this->t('input.duration.help_seconds') ?></p>
<?php elseif (is_string($slot['help']) && $facts['type'] === 'duration') : ?>
<p class="help" id="<?= $this->id('help', $slot['input']['key']) ?>"><?= $this->e($slot['help']) ?> <?= $this->t('input.duration.help') ?></p>
<?php elseif (is_string($slot['help'])) : ?>
<p class="help" id="<?= $this->id('help', $slot['input']['key']) ?>"><?= $this->e($slot['help']) ?></p>
<?php endif; ?>
<?php if (is_string($slot['error'])) : ?>
<p class="error-text" id="<?= $this->id('err', $slot['input']['key']) ?>"><?= $this->e($slot['error']) ?></p>
<?php endif; ?>
<?= $this->partial('field-input', $slot['input']) ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
</form>
<button class="button button-primary" type="submit" form="record-form"><?= $this->t('app.name') ?></button>
<?php endif; ?>
<?php if (is_array($data['values'] ?? null)) : ?>
<ul class="link-list">
<?php foreach ($data['values'] as $item) : ?>
<li><?= $this->value($item['field'], $item['value'], $item['mode']) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (is_array($data['table'] ?? null)) : ?>
<?= $this->partial('record-table', $data['table']) ?>
<?php endif; ?>
<?php if (is_array($data['pager'] ?? null)) : ?>
<?= $this->partial('pager', $data['pager']) ?>
<?php endif; ?>
<?php if (is_array($data['empty'] ?? null)) : ?>
<?= $this->partial('empty-state', $data['empty']) ?>
<?php endif; ?>
<?php if (is_array($data['inline'] ?? null)) : ?>
<p class="lead"><?= $this->partial('form-inline', ['nodes' => $data['inline']]) ?></p>
<?php endif; ?>
