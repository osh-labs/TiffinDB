<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The file page (docs/design/m4/files.md section 3): the file's name, the
 * image for the four image types (from the serving route) or a sentence
 * that the file is offered as a download, the facts, and the actions.
 * The layout prints the breadcrumb and the flash notice before this.
 *
 * Data: `name` (the file's name), `field` (the field's label), `record`
 * (the record's title), all plain text; `image` (the serving route, or
 * null when the file is not shown); `facts` (the facts partial's items);
 * `download` with `downloadQuery` (`download=1` for an image); `replace`
 * with `replaceQuery`, and `remove` (null when the person cannot edit);
 * `back` (the record).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->e($data['name']) ?></h1>
<p class="lead"><?= $this->t('files.show.lead', ['field' => $data['field'], 'title' => $data['record']]) ?></p>
</div>
</div>
<?php if (is_string($data['image'])) : ?>
<figure class="file-figure">
<img class="file-image-large" src="<?= $this->url($data['image']) ?>" alt="<?= $this->e($data['name']) ?>" />
</figure>
<?php else : ?>
<p class="static-value"><?= $this->t('files.show.not_shown') ?></p>
<?php endif; ?>
<?= $this->partial('facts', ['heading' => 'files.show.facts', 'id' => 'file', 'items' => $data['facts']]) ?>
<div class="form-actions">
<a class="button button-primary button-large" href="<?= $this->url($data['download'], $data['downloadQuery']) ?>"><?= $this->t('files.show.download') ?></a>
<?php if (is_string($data['replace'])) : ?>
<a class="button button-large" href="<?= $this->url($data['replace'], $data['replaceQuery']) ?>"><?= $this->t('files.show.replace') ?></a>
<?php endif; ?>
<?php if (is_string($data['remove'])) : ?>
<a class="button button-large button-danger" href="<?= $this->url($data['remove']) ?>"><?= $this->t('files.show.remove') ?></a>
<?php endif; ?>
<a class="button button-large" href="<?= $this->url($data['back']) ?>"><?= $this->t('files.show.back', ['title' => $data['record']]) ?></a>
</div>
