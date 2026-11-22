<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The trash, or one table's trash (docs/design/m4/trash.md section 2).
 * The layout prints the breadcrumb and the flash notice before this. No
 * script: every Restore is a POST form holding one button.
 *
 * Data: `lead` (plain text), `empty` (null, or the data of the
 * empty-state partial), `allTablesPath` (the trash, when one table is
 * shown; else null), `sections`: a list of {key, name, count (int),
 * records: list of {id, title, meta (plain text)}, canRestore (bool),
 * restorePath, restoreAllPath, view (`all` or `table`), page (string),
 * more (null or {path, query, count (formatted), items}), pager (null or
 * the data of the pager partial)}, `emptyPath` (the confirmation, for
 * Admins when anything is in the trash; else null).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('trash.title') ?></h1>
<?php if (is_array($data['empty'])) : ?>
<?= $this->partial('empty-state', $data['empty']) ?>
<?php else : ?>
<p class="lead"><?= $this->e($data['lead']) ?></p>
<?php if (is_string($data['allTablesPath'])) : ?>
<p><a href="<?= $this->url($data['allTablesPath']) ?>"><?= $this->t('trash.all_tables') ?></a></p>
<?php endif; ?>
<?php foreach ($data['sections'] as $section) : ?>
<section class="panel trash-section" aria-labelledby="<?= $this->id('trash', $section['key']) ?>">
<div class="trash-head">
<h2 id="<?= $this->id('trash', $section['key']) ?>"><?= $this->e($section['name']) ?> <span class="help"><?= $this->t('trash.section.count', ['count' => $section['count']]) ?></span></h2>
<?php if ($section['canRestore'] === true && $section['count'] > 0) : ?>
<form class="inline-form" method="post" action="<?= $this->url($section['restoreAllPath']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="table" value="<?= $this->e($section['key']) ?>" />
<button class="button button-plain" type="submit"><?= $this->t('trash.restore_all', ['table' => $section['name']]) ?></button>
</form>
<?php endif; ?>
</div>
<?php if (count($section['records']) > 0) : ?>
<ul class="trash-list">
<?php foreach ($section['records'] as $row) : ?>
<li class="trash-row">
<span class="trash-title"><?= $this->e($row['title']) ?> <span class="trash-meta"><?= $this->e($row['meta']) ?></span></span>
<?php if ($section['canRestore'] === true) : ?>
<form class="inline-form" method="post" action="<?= $this->url($section['restorePath']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="table" value="<?= $this->e($section['key']) ?>" />
<input type="hidden" name="record" value="<?= $this->e($row['id']) ?>" />
<input type="hidden" name="view" value="<?= $this->e($section['view']) ?>" />
<input type="hidden" name="page" value="<?= $this->e($section['page']) ?>" />
<button class="button button-plain" type="submit" aria-label="<?= $this->t('trash.restore_label', ['title' => $row['title']]) ?>"><?= $this->t('trash.restore') ?></button>
</form>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (is_array($section['more'])) : ?>
<p><a href="<?= $this->url($section['more']['path'], $section['more']['query']) ?>"><?= $this->t('trash.section.more', ['count' => $section['more']['count'], 'items' => $section['more']['items']]) ?></a></p>
<?php endif; ?>
<?php if (is_array($section['pager'])) : ?>
<?= $this->partial('pager', $section['pager']) ?>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php if (is_string($data['emptyPath'])) : ?>
<div class="danger-zone">
<p><?= $this->t('trash.empty.note') ?></p>
<a class="button button-danger button-large" href="<?= $this->url($data['emptyPath']) ?>"><?= $this->t('trash.empty.link') ?></a>
</div>
<?php endif; ?>
<?php endif; ?>
