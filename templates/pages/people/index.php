<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * People (docs/design/m1/people-and-sharing.md section 2). The layout
 * prints the flash notice before this page.
 *
 * Data: `people`, a list by name of {key, name, username, roleKey
 * (role.admin or role.member), projects ('all', 'none', or 'count'),
 * projectCount, active, self}.
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->t('people.title') ?></h1>
<p class="lead"><?= $this->t('people.lead') ?></p>
</div>
<div class="page-actions">
<a class="button button-primary" href="<?= $this->url('/admin/people/new') ?>"><?= $this->t('people.add') ?></a>
</div>
</div>
<div class="table-wrap">
<table class="data-table">
<caption class="visually-hidden"><?= $this->t('people.caption') ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('people.col.name') ?></th>
<th scope="col"><?= $this->t('people.col.username') ?></th>
<th scope="col"><?= $this->t('people.col.role') ?></th>
<th scope="col"><?= $this->t('people.col.projects') ?></th>
<th scope="col"><?= $this->t('people.col.status') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($data['people'] as $person) : ?>
<tr>
<?php if ($person['self'] === true) : ?>
<th scope="row"><a href="<?= $this->url('/admin/people/' . $person['key']) ?>"><?= $this->e($person['name']) ?></a> <?= $this->t('people.you') ?></th>
<?php else : ?>
<th scope="row"><a href="<?= $this->url('/admin/people/' . $person['key']) ?>"><?= $this->e($person['name']) ?></a></th>
<?php endif; ?>
<td><?= $this->e($person['username']) ?></td>
<td><?= $this->t($person['roleKey']) ?></td>
<?php if ($person['projects'] === 'all') : ?>
<td><?= $this->t('people.projects.all') ?></td>
<?php elseif ($person['projects'] === 'none') : ?>
<td><?= $this->t('people.projects.none') ?></td>
<?php else : ?>
<td><?= $this->count('count.projects', $person['projectCount']) ?></td>
<?php endif; ?>
<?php if ($person['active'] === true) : ?>
<td><?= $this->t('people.status.active') ?></td>
<?php else : ?>
<td><?= $this->t('people.status.inactive') ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="notice notice-muted"><?= $this->t('people.admin_note') ?></p>
