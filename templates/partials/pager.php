<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The pager under the record table (docs/design/m2/list-and-search.md
 * section 2 item 7): "Showing {from} to {to} of {total}", then Previous
 * and Next. A link exists when there is such a page; otherwise the button
 * is a `span` with `aria-disabled="true"`, which is not focusable.
 *
 * Data: `from`, `to`, `total`, `page`, `pages` (ints), `path` (the list
 * address), `query` (the list state as strings, without `page`).
 */

declare(strict_types=1);

?>
<?php $previous = $data['page'] - 1; ?>
<?php $next = $data['page'] + 1; ?>
<nav class="pager" aria-label="<?= $this->t('pager.label') ?>">
<span class="pager-status"><?= $this->t('pager.showing', ['from' => $this->number($data['from']), 'to' => $this->number($data['to']), 'total' => $this->number($data['total'])]) ?></span>
<div class="pager-links">
<?php if ($previous >= 1) : ?>
<a class="button pager-link" href="<?= $this->url($data['path'], ['page' => (string) $previous] + $data['query']) ?>" rel="prev"><?= $this->t('pager.previous') ?></a>
<?php else : ?>
<span class="button pager-link" aria-disabled="true"><?= $this->t('pager.previous') ?></span>
<?php endif; ?>
<?php if ($next <= $data['pages']) : ?>
<a class="button pager-link" href="<?= $this->url($data['path'], ['page' => (string) $next] + $data['query']) ?>" rel="next"><?= $this->t('pager.next') ?></a>
<?php else : ?>
<span class="button pager-link" aria-disabled="true"><?= $this->t('pager.next') ?></span>
<?php endif; ?>
</div>
</nav>
