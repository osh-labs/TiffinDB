<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Tables of a project (docs/design/m1/schema-editor.md section 3). The
 * layout prints the breadcrumb and the flash notice before this. Members
 * see the list without editing controls.
 *
 * Data: `project` {key, name}, `lead` (plain text), `canEdit` (bool),
 * `tables`: a list of {key, name, fields, records (formatted)}.
 */

declare(strict_types=1);

?>
<?php if ($data['canEdit'] === true) : ?>
<?= $this->partial('page-head', ['title' => $data['project']['name'], 'lead' => $data['lead'], 'actions' => [
    ['label' => 'schema.tables.add', 'path' => '/projects/' . $data['project']['key'] . '/tables/new', 'primary' => true],
    ['label' => 'projects.sharing', 'path' => '/projects/' . $data['project']['key'] . '/sharing', 'primary' => false],
    ['label' => 'schema.settings.title', 'path' => '/projects/' . $data['project']['key'] . '/settings', 'primary' => false],
]]) ?>
<?php else : ?>
<?= $this->partial('page-head', ['title' => $data['project']['name'], 'lead' => $data['lead']]) ?>
<?php endif; ?>
<?php if (count($data['tables']) > 0) : ?>
<div class="table-wrap">
<table class="data-table">
<caption class="visually-hidden"><?= $this->t('schema.tables.caption', ['project' => $data['project']['name']]) ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('schema.tables.col.table') ?></th>
<th class="num" scope="col"><?= $this->t('schema.tables.col.fields') ?></th>
<th class="num" scope="col"><?= $this->t('schema.tables.col.records') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($data['tables'] as $table) : ?>
<tr>
<?php if ($data['canEdit'] === true) : ?>
<th scope="row"><a href="<?= $this->url('/projects/' . $data['project']['key'] . '/tables/' . $table['key'] . '/fields') ?>" aria-label="<?= $this->t('schema.tables.change_label', ['table' => $table['name']]) ?>"><?= $this->e($table['name']) ?></a></th>
<?php else : ?>
<th scope="row"><?= $this->e($table['name']) ?></th>
<?php endif; ?>
<td class="num"><?= $this->e($table['fields']) ?></td>
<td class="num"><?= $this->e($table['records']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php elseif ($data['canEdit'] === true) : ?>
<p class="notice"><?= $this->t('schema.tables.empty.admin') ?></p>
<?php else : ?>
<p class="notice"><?= $this->t('schema.tables.empty.member') ?></p>
<?php endif; ?>
<?php if ($data['canEdit'] !== true) : ?>
<p class="notice notice-muted"><?= $this->t('schema.tables.member_note') ?></p>
<?php endif; ?>
