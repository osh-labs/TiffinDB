<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The inline content of a stored form's heading or paragraph (M2 spec
 * part 2 section 4.1; ADR 0014 decision 1): text escaped; `strong`, `em`,
 * and `br` as themselves; a link as `a.form-link` with its address
 * through formLink() (the only place it may be printed), the fixed
 * `rel="noopener noreferrer"`, and no `target` (M5 spec part 2 section
 * 6.3). Every line ends in a PHP tag, so no whitespace is added between
 * nodes.
 *
 * Data: `nodes` = list of {kind: 'text', text} | {kind: 'strong' | 'em',
 * children: list of nodes} | {kind: 'link', href (an http or https
 * address the parser accepted), children: list of nodes} | {kind: 'br'}.
 * Other kinds print nothing.
 */

declare(strict_types=1);

?>
<?php foreach ($data['nodes'] as $node) : ?>
<?php if ($node['kind'] === 'text') : ?>
<?= $this->e($node['text']) ?>
<?php elseif ($node['kind'] === 'strong') : ?>
<strong><?= $this->partial('form-inline', ['nodes' => $node['children']]) ?></strong><?php ?>
<?php elseif ($node['kind'] === 'em') : ?>
<em><?= $this->partial('form-inline', ['nodes' => $node['children']]) ?></em><?php ?>
<?php elseif ($node['kind'] === 'link') : ?>
<a class="form-link" href="<?= $this->formLink($node['href']) ?>" rel="noopener noreferrer"><?= $this->partial('form-inline', ['nodes' => $node['children']]) ?></a><?php ?>
<?php elseif ($node['kind'] === 'br') : ?>
<br /><?php ?>
<?php endif; ?>
<?php endforeach; ?>
