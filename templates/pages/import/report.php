<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import step 4, the report (docs/design/m3/import.md section 7), read
 * from the project's import log. Data: `steps`, `fileName`, `table`
 * ({name, path} or null when deleted since), `newTable`, `imported`,
 * `skipped`, `skippedParts` (texts), `emptied`, `linksAdded` (text or
 * null), `snapshot` (name or null), `columns` (list of {field, count,
 * rows}), `required` (list of {count, field}), `links` (list of {count,
 * table}), `another`. The summary is written here rather than with the
 * facts partial because the table's name is a link.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.title') ?></h1>
<?= $this->partial('steps', ['label' => 'steps.import.label', 'steps' => $data['steps']]) ?>
<section class="panel" aria-labelledby="<?= $this->id('i', 'summary') ?>">
<h2 id="<?= $this->id('i', 'summary') ?>"><?= $this->t('import.report.summary') ?></h2>
<dl class="facts">
<div>
<dt><?= $this->t('import.report.file') ?></dt>
<dd><?= $this->e($data['fileName']) ?></dd>
</div>
<div>
<dt><?= $this->t('import.report.table') ?></dt>
<?php if (is_array($data['table']) && $data['newTable'] === true) : ?>
<dd><a href="<?= $this->url($data['table']['path']) ?>"><?= $this->e($data['table']['name']) ?></a><?= $this->t('import.report.new_table') ?></dd>
<?php elseif (is_array($data['table'])) : ?>
<dd><a href="<?= $this->url($data['table']['path']) ?>"><?= $this->e($data['table']['name']) ?></a></dd>
<?php else : ?>
<dd><?= $this->t('import.report.table_gone') ?></dd>
<?php endif; ?>
</div>
<div>
<dt><?= $this->t('import.report.imported') ?></dt>
<dd><?= $this->e($data['imported']) ?></dd>
</div>
<div>
<dt><?= $this->t('import.report.skipped') ?></dt>
<dd><?= $this->e($data['skipped']) ?>
<?php if (count($data['skippedParts']) > 0) : ?>
<ul>
<?php foreach ($data['skippedParts'] as $part) : ?>
<li><?= $this->e($part) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</dd>
</div>
<div>
<dt><?= $this->t('import.report.emptied') ?></dt>
<dd><?= $this->e($data['emptied']) ?></dd>
</div>
<?php if (is_string($data['linksAdded'])) : ?>
<div>
<dt><?= $this->t('import.report.links') ?></dt>
<dd><?= $this->e($data['linksAdded']) ?></dd>
</div>
<?php endif; ?>
<?php if (is_string($data['snapshot'])) : ?>
<div>
<dt><?= $this->t('import.report.snapshot') ?></dt>
<dd><?= $this->t('import.report.snapshot_text', ['name' => $data['snapshot']]) ?></dd>
</div>
<?php endif; ?>
</dl>
</section>
<?php if (count($data['columns']) > 0) : ?>
<section class="panel" aria-labelledby="<?= $this->id('i', 'emptied') ?>">
<h2 id="<?= $this->id('i', 'emptied') ?>"><?= $this->t('import.report.columns') ?></h2>
<div class="table-scroll">
<table class="report-table">
<caption class="visually-hidden"><?= $this->t('import.report.caption') ?></caption>
<thead>
<tr><th scope="col"><?= $this->t('import.report.col_field') ?></th><th scope="col"><?= $this->t('import.report.col_count') ?></th><th scope="col"><?= $this->t('import.report.col_rows') ?></th></tr>
</thead>
<tbody>
<?php foreach ($data['columns'] as $column) : ?>
<tr><th scope="row"><?= $this->e($column['field']) ?></th><td><?= $this->e($column['count']) ?></td><td><?= $this->e($column['rows']) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="help"><?= $this->t('import.report.rows_help') ?></p>
</section>
<?php endif; ?>
<?php foreach ($data['required'] as $entry) : ?>
<p><?= $this->t('import.report.required', ['count' => $entry['count'], 'field' => $entry['field']]) ?></p>
<?php endforeach; ?>
<?php foreach ($data['links'] as $link) : ?>
<p><?= $this->t('import.report.links_added', ['count' => $link['count'], 'table' => $link['table']]) ?></p>
<?php endforeach; ?>
<div class="io-actions">
<?php if (is_array($data['table'])) : ?>
<a class="button button-primary button-large" href="<?= $this->url($data['table']['path']) ?>"><?= $this->t('import.report.open') ?></a>
<?php endif; ?>
<a class="button button-large" href="<?= $this->url($data['another']) ?>"><?= $this->t('import.report.another') ?></a>
</div>
