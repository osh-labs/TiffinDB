<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The project sidebar of the list view and search results
 * (docs/design/m2/list-and-search.md section 6): the project name and a
 * link to switch project, one link per table with its live record count
 * (the current table marked), the "Tables and fields" link, and the trash
 * count as text (not a link until the trash screen exists, P103). With
 * `data` (M3 spec part 2 section 4), a Data group after the tables links to
 * "Import a file" and "Export"; neither is ever marked current.
 *
 * Data: `sidebar`, from Sidebar::build().
 */

declare(strict_types=1);

?>
<nav class="sidebar" aria-label="<?= $this->t('sidebar.label') ?>">
<div class="sidebar-project">
<span class="sidebar-label"><?= $this->t('sidebar.project') ?></span>
<span class="sidebar-project-name"><?= $this->e($data['sidebar']['projectName']) ?></span>
<a class="sidebar-switch" href="<?= $this->url($data['sidebar']['switchPath']) ?>"><?= $this->t('sidebar.switch') ?></a>
</div>
<div class="sidebar-group">
<h2 class="sidebar-heading"><?= $this->t('sidebar.tables') ?></h2>
<?php foreach ($data['sidebar']['tables'] as $table) : ?>
<?php if ($table['current'] === true) : ?>
<a class="sidebar-link" href="<?= $this->url($table['path']) ?>" aria-current="page">
<?php else : ?>
<a class="sidebar-link" href="<?= $this->url($table['path']) ?>">
<?php endif; ?>
<span><?= $this->e($table['name']) ?></span>
<span class="sidebar-count"><?= $this->e($table['count']) ?></span>
</a>
<?php endforeach; ?>
</div>
<?php if (is_array($data['sidebar']['data'] ?? null)) : ?>
<div class="sidebar-group">
<h2 class="sidebar-heading"><?= $this->t('sidebar.data') ?></h2>
<a class="sidebar-link" href="<?= $this->url($data['sidebar']['data']['importPath']) ?>"><?= $this->t('sidebar.import') ?></a>
<a class="sidebar-link" href="<?= $this->url($data['sidebar']['data']['exportPath']) ?>"><?= $this->t('sidebar.export') ?></a>
</div>
<?php endif; ?>
<div class="sidebar-foot">
<a class="sidebar-link" href="<?= $this->url($data['sidebar']['schemaPath']) ?>"><?= $this->t('sidebar.schema') ?></a>
<div class="sidebar-item">
<span><?= $this->t('sidebar.trash') ?></span>
<span class="sidebar-count"><?= $this->e($data['sidebar']['trashCount']) ?></span>
</div>
</div>
</nav>
