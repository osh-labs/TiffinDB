<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The collection editor's preview (docs/design/m4/collections.md section 3
 * item 5): how many records match, then the first of them with the title
 * and up to three list columns, or a line saying nothing matches, or that
 * the rules need fixing first.
 *
 * Data (plain text unless noted): `heading`, `shown` (the formatted number
 * shown when more match than are shown, else null), `state` (`table`,
 * `none`, or `fix`), `caption`, `columns` (list of {header, numeric,
 * sort}), `rows`, and `itemNames`, which the shared record-table partial
 * draws as the list view does.
 */

declare(strict_types=1);

?>
<section class="collection-preview" aria-labelledby="c-res">
<?php if (is_string($data['shown'])) : ?>
<h2 id="c-res"><?= $this->e($data['heading']) ?> <span class="preview-count-note"><?= $this->t('collections.preview.first', ['shown' => $data['shown']]) ?></span></h2>
<?php else : ?>
<h2 id="c-res"><?= $this->e($data['heading']) ?></h2>
<?php endif; ?>
<?php if ($data['state'] === 'fix') : ?>
<p class="static-value"><?= $this->t('collections.preview.fix') ?></p>
<?php elseif ($data['state'] === 'none') : ?>
<p class="static-value"><?= $this->t('collections.preview.none') ?></p>
<?php else : ?>
<?= $this->partial('record-table', ['caption' => $data['caption'], 'columns' => $data['columns'], 'rows' => $data['rows'], 'itemNames' => $data['itemNames']]) ?>
<?php endif; ?>
</section>
