<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The snapshots of a project (docs/design/m7/snapshots.md section 2), for
 * Admins only (the controller asks the core first). The layout prints the
 * breadcrumb and the flash notice before this.
 *
 * Data: `action` (the list path, where "Take a snapshot now" posts),
 * `canRestore` (bool: this server can lock files), `snapshots`: a list,
 * newest first, of {when (text), datetime (UTC timestamp), reason (text),
 * size (text), restorePath (application path, or null when restoring is
 * turned off)}.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('snapshots.title') ?></h1>
<p class="lead"><?= $this->t('snapshots.lead') ?></p>
<p class="help"><?= $this->t('snapshots.files_note') ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'snapshots.take', 'danger' => false, 'size' => 'normal', 'cancel' => null]) ?>
</form>
<?php if ($data['canRestore'] !== true) : ?>
<?= $this->partial('notice', ['key' => 'snapshots.no_lock', 'params' => [], 'tone' => 'warn']) ?>
<?php endif; ?>
<?php if (count($data['snapshots']) === 0) : ?>
<?= $this->partial('empty-state', ['title' => 'snapshots.empty', 'body' => 'snapshots.empty_body']) ?>
<?php else : ?>
<div class="table-scroll">
<table class="data-table snapshot-table">
<caption class="visually-hidden"><?= $this->t('snapshots.table.caption') ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('snapshots.col.when') ?></th>
<th scope="col"><?= $this->t('snapshots.col.reason') ?></th>
<th scope="col" class="num"><?= $this->t('snapshots.col.size') ?></th>
<?php if ($data['canRestore'] === true) : ?>
<th scope="col" aria-label="<?= $this->t('snapshots.col.action') ?>"></th>
<?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($data['snapshots'] as $snapshot) : ?>
<tr>
<td><time datetime="<?= $this->e($snapshot['datetime']) ?>"><?= $this->e($snapshot['when']) ?></time></td>
<td><?= $this->e($snapshot['reason']) ?></td>
<td class="num"><?= $this->e($snapshot['size']) ?></td>
<?php if ($data['canRestore'] === true) : ?>
<?php if (is_string($snapshot['restorePath'])) : ?>
<td><a href="<?= $this->url($snapshot['restorePath']) ?>" aria-label="<?= $this->t('snapshots.restore_label', ['when' => $snapshot['when']]) ?>"><?= $this->t('snapshots.restore_link') ?></a></td>
<?php else : ?>
<td></td>
<?php endif; ?>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
