<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * What a form shows (docs/design/m5/components.md section 5), on the
 * editor and on the source view after Check or Save:
 * `section.panel.form-report` with its title, then, each only when it
 * applies and in this order: the "nothing needs attention" line (nothing
 * else to say), the fields not on the form with their list and the note
 * on required fields (only with `full`), the unknown field elements, the
 * pictures not shown, and the links without text.
 *
 * Data: `report` = {unknown: int (field elements naming no field of the
 * table), notShown: list of {name: plain text, required: bool},
 * requiredHidden: bool (a required field is not shown), images: int
 * (pictures not shown), emptyLinks: int}; `table` (the table's name,
 * plain text); `full` (bool: include the not-shown list; the editor
 * lists those fields itself).
 */

declare(strict_types=1);

?>
<?php $report = $data['report']; ?>
<?php $notShown = $data['full'] === true && count($report['notShown']) > 0; ?>
<section class="panel form-report" aria-labelledby="form-report-title">
<h2 class="section-title" id="form-report-title"><?= $this->t('forms.report.title') ?></h2>
<?php if (count($report['notShown']) === 0 && $report['unknown'] === 0 && $report['images'] === 0 && $report['emptyLinks'] === 0) : ?>
<p><?= $this->t('forms.report.ok', ['table' => $data['table']]) ?></p>
<?php endif; ?>
<?php if ($notShown) : ?>
<p><?= $this->t('forms.report.not_shown', ['count' => count($report['notShown'])]) ?></p>
<ul class="form-report-list">
<?php foreach ($report['notShown'] as $field) : ?>
<?php if ($field['required'] === true) : ?>
<li><?= $this->e($field['name']) ?> <span class="form-row-kind"><?= $this->t('forms.row.required') ?></span></li>
<?php else : ?>
<li><?= $this->e($field['name']) ?></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
<?php if ($report['requiredHidden'] === true) : ?>
<p><?= $this->t('forms.report.required') ?></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($report['unknown'] > 0) : ?>
<p><?= $this->t('forms.report.unknown', ['count' => $report['unknown'], 'table' => $data['table']]) ?></p>
<?php endif; ?>
<?php if ($report['images'] > 0) : ?>
<p><?= $this->t('forms.report.images', ['count' => $report['images']]) ?></p>
<?php endif; ?>
<?php if ($report['emptyLinks'] > 0) : ?>
<p><?= $this->t('forms.report.empty_links', ['count' => $report['emptyLinks']]) ?></p>
<?php endif; ?>
</section>
