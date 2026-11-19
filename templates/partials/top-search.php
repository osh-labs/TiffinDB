<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The project search in the top bar (docs/design/m2/list-and-search.md
 * section 5): a GET form to the project's search results with one search
 * input, so Enter submits it; no submit button. The magnifier is
 * decorative.
 *
 * Data: `search` = {project: project key, projectName: plain text, q:
 * the current search text on the results page, else null}.
 */

declare(strict_types=1);

?>
<form class="top-search" role="search" method="get" action="<?= $this->url('/projects/' . $data['search']['project'] . '/search') ?>">
<svg class="top-search-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="8.5" cy="8.5" r="6" /><path d="M13 13l5 5" /></svg>
<?php if (is_string($data['search']['q'] ?? null)) : ?>
<input class="top-search-input" id="top-search" type="search" name="q" maxlength="200" value="<?= $this->e($data['search']['q']) ?>" aria-label="<?= $this->t('shell.search.label', ['project' => $data['search']['projectName']]) ?>" placeholder="<?= $this->t('shell.search.placeholder', ['project' => $data['search']['projectName']]) ?>" />
<?php else : ?>
<input class="top-search-input" id="top-search" type="search" name="q" maxlength="200" aria-label="<?= $this->t('shell.search.label', ['project' => $data['search']['projectName']]) ?>" placeholder="<?= $this->t('shell.search.placeholder', ['project' => $data['search']['projectName']]) ?>" />
<?php endif; ?>
</form>
