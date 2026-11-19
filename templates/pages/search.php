<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Search results across the tables of a project
 * (docs/design/m2/list-and-search.md section 4). The layout prints the
 * sidebar and, in `main`, the flash notice before this.
 *
 * Data (plain text unless noted): `heading`, `lead` (or null), `problem`
 * (or null), `q` (the search text, for the result links), `projectName`,
 * `groups` (list of {key (table key), name, total (int), hits: list of
 * {path, title, match (or null)}, more: null or {path, count}}), `none`
 * (bool: a search found nothing).
 */

declare(strict_types=1);

?>
<?= $this->partial('page-head', ['title' => $data['heading'], 'lead' => $data['lead']]) ?>
<?php if (is_string($data['problem'])) : ?>
<div class="notice notice-warn" role="alert">
<p><?= $this->e($data['problem']) ?></p>
</div>
<?php endif; ?>
<?php foreach ($data['groups'] as $group) : ?>
<section class="result-group" aria-labelledby="<?= $this->id('group', $group['key']) ?>">
<h2 class="section-title" id="<?= $this->id('group', $group['key']) ?>"><?= $this->t('search.group', ['table' => $group['name'], 'count' => $group['total']]) ?></h2>
<ol class="result-list">
<?php foreach ($group['hits'] as $hit) : ?>
<li class="result">
<a class="result-title" href="<?= $this->url($hit['path'], ['q' => $data['q']]) ?>"><?= $this->e($hit['title']) ?></a>
<?php if (is_string($hit['match'])) : ?>
<p class="result-match"><?= $this->e($hit['match']) ?></p>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ol>
<?php if (is_array($group['more'])) : ?>
<a class="result-more" href="<?= $this->url($group['more']['path'], ['q' => $data['q']]) ?>"><?= $this->t('search.more', ['count' => $group['more']['count'], 'table' => $group['name']]) ?></a>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php if ($data['none'] === true) : ?>
<?= $this->partial('empty-state', ['title' => 'search.none.title', 'body' => 'search.none.body', 'bodyParams' => ['project' => $data['projectName'], 'q' => $data['q']]]) ?>
<?php endif; ?>
