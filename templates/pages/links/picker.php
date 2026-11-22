<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The relation picker (docs/design/m4/picker.md section 2). The layout
 * prints the breadcrumb, the flash notice, and the error summary (422
 * after a refused link) before this.
 *
 * Data: `field` (the field's label), `record` (the record's title),
 * `item` and `items` (the other table's item name and name), all plain
 * text; `onlyOne` (bool: the field allows one link); `linked` (list of
 * {path, title, target}); `trashed` (int: links to records in the trash);
 * `blocked` (bool: a one-link field whose link is in the trash, so the
 * candidates have no buttons); `path` (this page), `add` and `remove`
 * (the POST addresses); `q` (the find text the core accepted, '' for
 * none, posted back by every press) and `typed` (the find text as given,
 * shown in the box); `page` (the page number, posted back);
 * `searchProblem` (plain text or null); `candidates` (list of {path,
 * title, target, taken (null, or the title of the record it is linked
 * to)}); `total` (int); `pager` (null, or the pager partial's data);
 * `back` (the record); `addNew` (the other table's new-record form, or
 * null) with `addNewQuery` ({from}).
 *
 * Every press is its own small POST form (no nested forms, no script);
 * each button names its record in its aria-label.
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->t('links.title', ['field' => $data['field'], 'title' => $data['record']]) ?></h1>
<?php if ($data['onlyOne'] === true) : ?>
<p class="lead"><?= $this->t('links.lead_one', ['item' => $data['item']]) ?></p>
<?php else : ?>
<p class="lead"><?= $this->t('links.lead_many') ?></p>
<?php endif; ?>
</div>
</div>
<section class="picker-section" aria-labelledby="pk-now">
<h2 id="pk-now"><?= $this->t('links.now.heading') ?></h2>
<?php if (count($data['linked']) > 0) : ?>
<ul class="picker-list">
<?php foreach ($data['linked'] as $link) : ?>
<li class="picker-row">
<span class="picker-title"><a href="<?= $this->url($link['path']) ?>"><?= $this->e($link['title']) ?></a></span>
<form class="inline-form" method="post" action="<?= $this->url($data['remove']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="target" value="<?= $this->e($link['target']) ?>" />
<input type="hidden" name="back" value="picker" />
<input type="hidden" name="q" value="<?= $this->e($data['q']) ?>" />
<input type="hidden" name="page" value="<?= $this->e($data['page']) ?>" />
<button class="button button-plain" type="submit" aria-label="<?= $this->t('links.unlink_label', ['title' => $link['title']]) ?>"><?= $this->t('links.unlink') ?></button>
</form>
</li>
<?php endforeach; ?>
</ul>
<?php else : ?>
<p class="static-value"><?= $this->t('links.now.none') ?></p>
<?php endif; ?>
<?php if ($data['trashed'] > 0) : ?>
<p class="help"><?= $this->t('links.now.trashed', ['count' => $data['trashed']]) ?></p>
<?php endif; ?>
<?php if ($data['blocked'] === true) : ?>
<p class="notice notice-warn"><?= $this->t('links.now.trashed_one', ['item' => $data['item']]) ?></p>
<?php endif; ?>
</section>
<form class="picker-find" method="get" action="<?= $this->url($data['path']) ?>">
<div class="field">
<label class="label" for="<?= $this->id('f', 'q') ?>"><?= $this->t('links.find.label', ['items' => $data['items']]) ?></label>
<?php if (is_string($data['searchProblem'])) : ?>
<p class="error-text" id="<?= $this->id('err', 'q') ?>"><?= $this->partial('field-error', ['text' => $data['searchProblem']]) ?></p>
<input class="input" id="<?= $this->id('f', 'q') ?>" type="search" name="q" maxlength="200" value="<?= $this->e($data['typed']) ?>" aria-invalid="true" aria-describedby="<?= $this->id('err', 'q') ?>" />
<?php else : ?>
<input class="input" id="<?= $this->id('f', 'q') ?>" type="search" name="q" maxlength="200" value="<?= $this->e($data['typed']) ?>" />
<?php endif; ?>
</div>
<button class="button" type="submit"><?= $this->t('links.find.button') ?></button>
<?php if ($data['typed'] !== '') : ?>
<a href="<?= $this->url($data['path']) ?>"><?= $this->t('links.find.clear') ?></a>
<?php endif; ?>
</form>
<section class="picker-section" aria-labelledby="pk-list">
<?php if ($data['q'] === '') : ?>
<h2 id="pk-list"><?= $this->t('links.list.heading', ['items' => $data['items']]) ?> <span class="help"><?= $this->t('links.list.count', ['count' => $data['total']]) ?></span></h2>
<?php else : ?>
<h2 id="pk-list"><?= $this->t('links.list.heading_found', ['items' => $data['items']]) ?> <span class="help"><?= $this->t('links.list.count', ['count' => $data['total']]) ?></span></h2>
<?php endif; ?>
<?php if (count($data['candidates']) > 0) : ?>
<ul class="picker-list">
<?php foreach ($data['candidates'] as $candidate) : ?>
<li class="picker-row">
<span class="picker-title"><a href="<?= $this->url($candidate['path']) ?>"><?= $this->e($candidate['title']) ?></a></span>
<?php if (is_string($candidate['taken'])) : ?>
<span class="picker-taken"><?= $this->t('links.taken', ['title' => $candidate['taken']]) ?></span>
<?php elseif ($data['blocked'] !== true) : ?>
<form class="inline-form" method="post" action="<?= $this->url($data['add']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="target" value="<?= $this->e($candidate['target']) ?>" />
<input type="hidden" name="back" value="picker" />
<input type="hidden" name="q" value="<?= $this->e($data['q']) ?>" />
<input type="hidden" name="page" value="<?= $this->e($data['page']) ?>" />
<?php if ($data['onlyOne'] === true) : ?>
<button class="button button-plain" type="submit" aria-label="<?= $this->t('links.choose_label', ['title' => $candidate['title']]) ?>"><?= $this->t('links.choose') ?></button>
<?php else : ?>
<button class="button button-plain" type="submit" aria-label="<?= $this->t('links.link_label', ['title' => $candidate['title']]) ?>"><?= $this->t('links.link') ?></button>
<?php endif; ?>
</form>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php elseif ($data['q'] === '') : ?>
<p class="static-value"><?= $this->t('links.list.none', ['items' => $data['items']]) ?></p>
<?php else : ?>
<p class="static-value"><?= $this->t('links.list.none_found', ['items' => $data['items'], 'q' => $data['q']]) ?></p>
<?php endif; ?>
<?php if (is_array($data['pager'])) : ?>
<?= $this->partial('pager', $data['pager']) ?>
<?php endif; ?>
</section>
<div class="form-actions">
<a class="button button-primary button-large" href="<?= $this->url($data['back']) ?>"><?= $this->t('links.back', ['title' => $data['record']]) ?></a>
<?php if (is_string($data['addNew']) && $data['onlyOne'] === true) : ?>
<a class="button button-large" href="<?= $this->url($data['addNew'], $data['addNewQuery']) ?>"><?= $this->t('links.add_new_one', ['item' => $data['item']]) ?></a>
<?php elseif (is_string($data['addNew'])) : ?>
<a class="button button-large" href="<?= $this->url($data['addNew'], $data['addNewQuery']) ?>"><?= $this->t('links.add_new', ['item' => $data['item']]) ?></a>
<?php endif; ?>
</div>
