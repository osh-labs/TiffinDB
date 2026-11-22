<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The simple form editor (docs/design/m5/editor.md section 2; M5 spec
 * part 2 sections 5 and 6.5). The layout prints the breadcrumb, the flash
 * notice, and the error summary first.
 *
 * One form, `form#form-editor`, holds the hidden draft and base, every
 * row's inputs, and every button; each button is `name="do"`. The first
 * submit button in tree order is the hidden Apply (`tabindex="-1"`), so
 * Enter in a text box applies the typed labels and names (P163). No
 * script: the width menu does not submit on change (WCAG 3.2.2).
 *
 * Data (plain text unless noted; paths are application paths): `base`
 * (the table's path), `table` {name}, `rows` (list of {row, number} for
 * forms/row, children nested), `focusAddSection` (bool), `notOnForm`
 * (list of {key, name, typeLabel, required}), `report` (forms/report
 * data), `showReport` (bool), `draft` (the canonical text of the working
 * copy), `formBase` (the save token), `state` ('stored', 'none', or
 * 'refused'), `isStandard` (bool).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->t('forms.editor.title', ['table' => $data['table']['name']]) ?></h1>
<p class="lead"><?= $this->t('forms.editor.lead') ?></p>
</div>
</div>
<?= $this->partial('forms/tabs', ['base' => $data['base'], 'current' => 'arrange']) ?>
<?php if ($data['state'] === 'refused') : ?>
<div class="notice notice-warn">
<p class="notice-title"><?= $this->t('forms.editor.refused_title') ?></p>
<p><?= $this->t('forms.editor.refused') ?></p>
</div>
<?php elseif ($data['isStandard'] === true) : ?>
<p class="notice notice-muted"><?= $this->t('forms.editor.standard') ?></p>
<?php else : ?>
<p class="notice notice-muted"><?= $this->t('forms.editor.edited') ?></p>
<?php endif; ?>
<p class="help" id="<?= $this->id('editor', 'help') ?>"><?= $this->t('forms.editor.help') ?></p>
<form id="form-editor" method="post" action="<?= $this->url($data['base'] . '/form') ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="draft" value="<?= $this->e($data['draft']) ?>" />
<input type="hidden" name="base" value="<?= $this->e($data['formBase']) ?>" />
<button class="visually-hidden" type="submit" name="do" value="apply" tabindex="-1"><?= $this->t('forms.editor.apply') ?></button>
<ol class="form-rows" aria-label="<?= $this->t('forms.editor.rows_label') ?>">
<?php foreach ($data['rows'] as $item) : ?>
<?= $this->partial('forms/row', ['row' => $item['row'], 'number' => $item['number']]) ?>
<?php endforeach; ?>
</ol>
<div>
<?php if ($data['focusAddSection'] === true) : ?>
<button class="button" type="submit" name="do" value="add-section" autofocus="autofocus"><?= $this->t('forms.editor.add_section') ?></button>
<?php else : ?>
<button class="button" type="submit" name="do" value="add-section"><?= $this->t('forms.editor.add_section') ?></button>
<?php endif; ?>
</div>
<section class="panel" aria-labelledby="not-on-form-title">
<h2 class="section-title" id="not-on-form-title"><?= $this->t('forms.editor.not_on_form') ?></h2>
<?php if (count($data['notOnForm']) === 0) : ?>
<p><?= $this->t('forms.editor.all_on_form', ['table' => $data['table']['name']]) ?></p>
<?php else : ?>
<ul class="form-rows">
<?php foreach ($data['notOnForm'] as $field) : ?>
<li class="form-row">
<div class="form-row-head">
<span class="form-row-name"><?= $this->e($field['name']) ?></span>
<span class="form-row-kind"><?= $this->e($field['typeLabel']) ?></span>
<?php if ($field['required'] === true) : ?>
<span class="form-row-kind"><?= $this->t('forms.row.required') ?></span>
<?php endif; ?>
</div>
<?php if ($field['required'] === true) : ?>
<p class="help form-row-note"><?= $this->t('forms.editor.required_hidden') ?></p>
<?php endif; ?>
<div class="form-row-actions">
<button class="button button-plain" type="submit" name="do" value="<?= $this->e('add-' . $field['key']) ?>" aria-label="<?= $this->t('forms.editor.add_aria', ['field' => $field['name']]) ?>"><?= $this->t('forms.editor.add') ?></button>
</div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
<?php if ($data['showReport'] === true) : ?>
<?= $this->partial('forms/report', ['report' => $data['report'], 'table' => $data['table']['name'], 'full' => false]) ?>
<?php endif; ?>
<div class="form-actions">
<button class="button button-primary button-large" type="submit" name="do" value="save"><?= $this->t('forms.editor.save') ?></button>
<a class="button button-large" href="<?= $this->url($data['base'] . '/fields') ?>"><?= $this->t('forms.editor.cancel') ?></a>
<p class="help"><?= $this->t('forms.editor.unsaved') ?></p>
</div>
</form>
