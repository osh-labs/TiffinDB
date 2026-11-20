<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The files of an attachment field (docs/design/m4/components.md section
 * 3.3; M4 spec part 2 sections 6.1 and 6.2), printed inside the field's
 * `div.field` after its label, help, and errors: `ul.file-list` with one
 * `li.file-row` per file (an image link for the four image types, the
 * name and facts, Open, and, when the person can edit, Replace and
 * Remove, each named for its file) or "No files"; then "Add a file" (or
 * "Add an image") and the leave note. On a new record: only the
 * after-save note (M4 D-38).
 *
 * An image is shown only when the controller gives `image`: its `src` is
 * the serving route `/projects/{p}/files/{a}`, through url(), and its
 * `alt` the file's name. No size is printed; the stylesheet bounds it.
 *
 * Data: `key` (the field key, the id part of its label), `files` (list of
 * {name, type (the type word, plain: the text of file.type.pdf,
 * file.type.image, or file.type.other), size (formatted, plain), openPath,
 * replacePath (the upload page, or null) with optional replaceQuery (its
 * query, `['replace' => '<attachment id>']`), removePath (or null), image
 * (null or {src, alt})}), `addPath` (the upload page, or null when the person cannot edit
 * or the record is new), `imagesOnly` (bool), `isNew` (bool). Every text
 * is plain and escaped here; paths go through url().
 */

declare(strict_types=1);

?>
<?php if ($data['isNew'] === true) : ?>
<p class="help"><?= $this->t('components.file.after_save') ?></p>
<?php else : ?>
<?php if (count($data['files']) > 0) : ?>
<ul class="file-list">
<?php foreach ($data['files'] as $file) : ?>
<?php if (is_array($file['image'])) : ?>
<li class="file-row file-row-image">
<a class="file-image-link" href="<?= $this->url($file['openPath']) ?>"><img class="file-image" src="<?= $this->url($file['image']['src']) ?>" alt="<?= $this->e($file['image']['alt']) ?>" /></a>
<?php else : ?>
<li class="file-row">
<?php endif; ?>
<span class="file-name"><?= $this->e($file['name']) ?> <span class="file-facts"><?= $this->t('input.file.facts', ['type' => $file['type'], 'size' => $file['size']]) ?></span></span>
<a class="file-open" href="<?= $this->url($file['openPath']) ?>" aria-label="<?= $this->t('components.file.open_label', ['name' => $file['name']]) ?>"><?= $this->t('components.file.open') ?></a>
<?php if (is_string($file['replacePath'])) : ?>
<a class="button button-plain" href="<?= $this->url($file['replacePath'], $file['replaceQuery'] ?? []) ?>" aria-label="<?= $this->t('components.file.replace_label', ['name' => $file['name']]) ?>"><?= $this->t('components.file.replace') ?></a>
<?php endif; ?>
<?php if (is_string($file['removePath'])) : ?>
<a class="button button-plain" href="<?= $this->url($file['removePath']) ?>" aria-label="<?= $this->t('components.file.remove_label', ['name' => $file['name']]) ?>"><?= $this->t('components.file.remove') ?></a>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php else : ?>
<p class="static-value"><?= $this->t('input.files.none') ?></p>
<?php endif; ?>
<?php if (is_string($data['addPath'])) : ?>
<div class="control-actions">
<?php if ($data['imagesOnly'] === true) : ?>
<a class="button button-strong" href="<?= $this->url($data['addPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.file.add_image') ?></a>
<?php else : ?>
<a class="button button-strong" href="<?= $this->url($data['addPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.file.add') ?></a>
<?php endif; ?>
</div>
<?= $this->partial('leave-note') ?>
<?php endif; ?>
<?php endif; ?>
