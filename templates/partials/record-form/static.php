<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Text blocks of a bound record form (docs/design/m2/record.md section
 * 2.1): a heading (`h2.section-title` or `h3`), a paragraph, a divider,
 * or a form image the core found servable (docs/design/m4/
 * record-controls.md section 1): `img.file-image` from the serving route.
 * Inline content goes through the form-inline partial (text escaped;
 * `strong`, `em`, `br` as themselves; links as `a.form-link`, M5 spec
 * part 2 section 6.3). Other kinds print nothing. In column mode the
 * record-form/cell partial wraps the block.
 *
 * Data: `node` = {kind: 'heading', level: 2|3, children} | {kind:
 * 'paragraph', children} | {kind: 'divider'} | {kind: 'image', src (the
 * serving route `/projects/{p}/files/{a}`), alt (plain text)}; children
 * are form-inline nodes.
 */

declare(strict_types=1);

?>
<?php if ($data['node']['kind'] === 'heading' && $data['node']['level'] === 3) : ?>
<h3><?= $this->partial('form-inline', ['nodes' => $data['node']['children']]) ?></h3>
<?php elseif ($data['node']['kind'] === 'heading') : ?>
<h2 class="section-title"><?= $this->partial('form-inline', ['nodes' => $data['node']['children']]) ?></h2>
<?php elseif ($data['node']['kind'] === 'paragraph') : ?>
<p><?= $this->partial('form-inline', ['nodes' => $data['node']['children']]) ?></p>
<?php elseif ($data['node']['kind'] === 'divider') : ?>
<hr />
<?php elseif ($data['node']['kind'] === 'image') : ?>
<img class="file-image" src="<?= $this->url($data['node']['src']) ?>" alt="<?= $this->e($data['node']['alt']) ?>" />
<?php endif; ?>
