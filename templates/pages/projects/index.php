<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Project list (docs/design/m1/sign-in-and-projects.md section 3). The
 * layout prints the flash notice before this.
 *
 * Data: `title` and `lead` (plain text), `admin` (bool: the Admin-only
 * controls), `projects`: a list of
 * {key, name, available (bool), tables (names joined, or null), records
 * (formatted), changed (relative date or null), changedBy (display name
 * or null), members (int)}, by name.
 */

declare(strict_types=1);

?>
<?php if ($data['admin'] === true) : ?>
<?= $this->partial('page-head', ['title' => $data['title'], 'lead' => $data['lead'], 'actions' => [['label' => 'projects.new', 'path' => '/projects/new', 'primary' => true, 'icon' => 'plus']]]) ?>
<?php else : ?>
<?= $this->partial('page-head', ['title' => $data['title'], 'lead' => $data['lead']]) ?>
<?php endif; ?>
<?php if (count($data['projects']) > 0) : ?>
<ul class="project-grid">
<?php foreach ($data['projects'] as $project) : ?>
<li class="card project-card">
<?php if ($project['available'] === true) : ?>
<h2 class="project-name"><a href="<?= $this->url('/projects/' . $project['key']) ?>"><?= $this->e($project['name']) ?></a></h2>
<dl class="facts">
<dt><?= $this->t('projects.fact.tables') ?></dt>
<?php if (is_string($project['tables'])) : ?>
<dd><?= $this->e($project['tables']) ?></dd>
<?php else : ?>
<dd><?= $this->t('projects.no_tables') ?></dd>
<?php endif; ?>
<dt><?= $this->t('projects.fact.records') ?></dt>
<dd><?= $this->e($project['records']) ?></dd>
<dt><?= $this->t('projects.fact.changed') ?></dt>
<?php if (!is_string($project['changed'])) : ?>
<dd><?= $this->t('projects.never_changed') ?></dd>
<?php elseif (is_string($project['changedBy'])) : ?>
<dd><?= $this->t('date.last_change', ['date' => $project['changed'], 'name' => $project['changedBy']]) ?></dd>
<?php else : ?>
<dd><?= $this->t('date.last_change_unknown', ['date' => $project['changed']]) ?></dd>
<?php endif; ?>
<?php if ($data['admin'] === true) : ?>
<dt><?= $this->t('projects.fact.shared') ?></dt>
<?php if ($project['members'] > 0) : ?>
<dd><?= $this->count('count.people', $project['members']) ?></dd>
<?php else : ?>
<dd><?= $this->t('projects.shared.none') ?></dd>
<?php endif; ?>
<?php endif; ?>
</dl>
<?php else : ?>
<h2 class="project-name"><?= $this->e($project['name']) ?></h2>
<p class="notice notice-warn"><?= $this->t('projects.unavailable') ?></p>
<?php endif; ?>
<?php if ($project['available'] === true || $data['admin'] === true) : ?>
<div class="card-links link-row">
<?php if ($project['available'] === true) : ?>
<a class="link-strong" href="<?= $this->url('/projects/' . $project['key']) ?>" aria-label="<?= $this->t('projects.open.label', ['name' => $project['name']]) ?>"><?= $this->t('projects.open') ?></a>
<?php endif; ?>
<?php if ($data['admin'] === true) : ?>
<a href="<?= $this->url('/projects/' . $project['key'] . '/sharing') ?>" aria-label="<?= $this->t('projects.sharing.label', ['name' => $project['name']]) ?>"><?= $this->t('projects.sharing') ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php elseif ($data['admin'] === true) : ?>
<p class="notice"><?= $this->t('projects.empty.admin') ?></p>
<?php else : ?>
<p class="notice"><?= $this->t('projects.empty.member') ?></p>
<?php endif; ?>
<?php if ($data['admin'] === true) : ?>
<p class="notice notice-muted"><?= $this->t('projects.admin_note') ?></p>
<?php endif; ?>
