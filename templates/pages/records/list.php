<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The list view of a table (docs/design/m2/list-and-search.md section 2).
 * The layout prints the sidebar, then in `main` the flash notice (with
 * Undo after a move to the trash) before this.
 *
 * Data (plain text unless noted; paths are application paths; queries are
 * string maps): `tableName`, `itemName`, `lead`, `addPath` (or null when
 * the table has no fields), `state` (the list state), `listPath`,
 * `filterPath`, `controls` (bool: the table has live records), `hidden`
 * (list of {name, value}: the active filter), `find` (the find box value),
 * `findError` (or null), `sortOptions` (list of {token, label, selected}),
 * `condition` (or null), `withoutFilter` (query), `filterProblem` (or
 * null), `empty` (null, or the data of the empty-state partial), `table`
 * (null, or the data of the record-table partial), `pager` (null, or the
 * data of the pager partial).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->e($data['tableName']) ?></h1>
<p class="lead"><?= $this->e($data['lead']) ?></p>
</div>
<?php if (is_string($data['addPath'])) : ?>
<div class="page-actions">
<a class="button button-primary" href="<?= $this->url($data['addPath'], $data['state']) ?>">
<svg class="button-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M9 2v14M2 9h14" /></svg>
<span><?= $this->t('lists.add', ['item' => $data['itemName']]) ?></span>
</a>
</div>
<?php endif; ?>
</div>
<?php if ($data['controls'] === true) : ?>
<form class="list-controls" method="get" action="<?= $this->url($data['listPath']) ?>">
<?php foreach ($data['hidden'] as $hidden) : ?>
<input type="hidden" name="<?= $this->e($hidden['name']) ?>" value="<?= $this->e($hidden['value']) ?>" />
<?php endforeach; ?>
<div class="field list-find">
<label class="label label-small" for="find"><?= $this->t('lists.find.label', ['table' => $data['tableName']]) ?></label>
<?php if (is_string($data['findError'])) : ?>
<input class="input input-compact" id="find" type="search" name="q" value="<?= $this->e($data['find']) ?>" placeholder="<?= $this->t('lists.find.placeholder') ?>" maxlength="200" aria-describedby="find-error" aria-invalid="true" />
<p class="error-text" id="find-error"><?= $this->e($data['findError']) ?></p>
<?php else : ?>
<input class="input input-compact" id="find" type="search" name="q" value="<?= $this->e($data['find']) ?>" placeholder="<?= $this->t('lists.find.placeholder') ?>" maxlength="200" />
<?php endif; ?>
</div>
<div class="field list-sort">
<label class="label label-small" for="sort"><?= $this->t('lists.sort.label') ?></label>
<select class="input input-compact" id="sort" name="sort">
<?php foreach ($data['sortOptions'] as $option) : ?>
<?php if ($option['selected'] === true) : ?>
<option value="<?= $this->e($option['token']) ?>" selected="selected"><?= $this->e($option['label']) ?></option>
<?php else : ?>
<option value="<?= $this->e($option['token']) ?>"><?= $this->e($option['label']) ?></option>
<?php endif; ?>
<?php endforeach; ?>
</select>
</div>
<button class="button" type="submit"><?= $this->t('lists.apply') ?></button>
<a class="button" href="<?= $this->url($data['filterPath'], $data['state']) ?>"><?= $this->t('lists.filter.open') ?></a>
</form>
<?php if (is_string($data['condition'])) : ?>
<div class="notice filter-bar">
<p><?= $this->t('lists.filter.active', ['condition' => $data['condition']]) ?></p>
<a href="<?= $this->url($data['listPath'], $data['withoutFilter']) ?>"><?= $this->t('lists.filter.remove') ?></a>
</div>
<?php endif; ?>
<?php if (is_string($data['filterProblem'])) : ?>
<div class="notice notice-warn" role="alert">
<p><?= $this->t('lists.filter.invalid') ?></p>
<p><?= $this->e($data['filterProblem']) ?></p>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if (is_array($data['empty'])) : ?>
<?= $this->partial('empty-state', $data['empty']) ?>
<?php endif; ?>
<?php if (is_array($data['table'])) : ?>
<?= $this->partial('record-table', $data['table']) ?>
<?php endif; ?>
<?php if (is_array($data['pager'])) : ?>
<?= $this->partial('pager', $data['pager']) ?>
<?php endif; ?>
