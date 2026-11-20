<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The control of a relation field that allows one link
 * (docs/design/m4/components.md section 3.1; M4 spec part 2 section 6.1),
 * printed inside the field's `div.field` after its label, help, and
 * errors: `div.link-control` with the linked title as a link or "No {item}
 * chosen", and the link to the picker when the person can edit; then the
 * table help, and the leave note. On a new record: no control, the table
 * help and the after-save note (M4 D-38).
 *
 * The choose link is described by the field's label, the `p.label` with
 * id('f', key) that the calling slot prints.
 *
 * Data: `key` (the field key, the id part of the label), `link` (null or
 * {path, title}: the linked record's address and title), `choosePath` (the
 * picker's address, or null when the person cannot edit or the record is
 * new), `item` (the other table's item name, plain), `table` (its name,
 * plain), `required` (bool; the label shows the marker), `isNew` (bool).
 * Every text is plain and escaped here; paths go through url().
 */

declare(strict_types=1);

?>
<?php if ($data['isNew'] !== true) : ?>
<div class="link-control">
<?php if (is_array($data['link'])) : ?>
<a class="link-chosen" href="<?= $this->url($data['link']['path']) ?>"><?= $this->e($data['link']['title']) ?></a>
<?php else : ?>
<span class="link-none"><?= $this->t('components.link.none', ['item' => $data['item']]) ?></span>
<?php endif; ?>
<?php if (is_string($data['choosePath']) && is_array($data['link'])) : ?>
<a class="button button-plain" href="<?= $this->url($data['choosePath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.link.choose_other', ['item' => $data['item']]) ?></a>
<?php elseif (is_string($data['choosePath'])) : ?>
<a class="button button-plain" href="<?= $this->url($data['choosePath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.link.choose') ?></a>
<?php endif; ?>
</div>
<?php endif; ?>
<p class="help"><?= $this->t('input.links.help', ['table' => $data['table']]) ?></p>
<?php if ($data['isNew'] === true) : ?>
<p class="help"><?= $this->t('components.link.after_save') ?></p>
<?php elseif (is_string($data['choosePath'])) : ?>
<?= $this->partial('leave-note') ?>
<?php endif; ?>
