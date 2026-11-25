<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A component's licence text (docs/design/m7/about.md section 3): the one
 * template that may hold a `pre` element (M7 screens spec section 6.4).
 *
 * Data from AboutController: `name` (the component's name, plain text),
 * `files` (list of {name: the file's last path segment, path: its path
 * in this installation, text: the licence text, cut: bool}). Every text
 * is printed with e(), inside `pre.licence-text` as text content only.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('about.licence.title', ['name' => $data['name']]) ?></h1>
<p><a href="<?= $this->url('/about') ?>"><?= $this->t('about.licence.back') ?></a></p>
<?php foreach ($data['files'] as $file) : ?>
<?php if (count($data['files']) > 1) : ?>
<h2><?= $this->e($file['name']) ?></h2>
<?php endif; ?>
<pre class="licence-text"><?= $this->e($file['text']) ?></pre>
<?php if ($file['cut'] === true) : ?>
<p class="help"><?= $this->t('about.licence.cut', ['file' => $file['path']]) ?></p>
<?php endif; ?>
<?php endforeach; ?>
