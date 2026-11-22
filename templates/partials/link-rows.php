<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The linked-record rows of a relation field that allows many links
 * (docs/design/m4/components.md section 3.2; M4 spec part 2 section 6.1),
 * printed inside the field's `div.field` after its label, help, and
 * errors: `ul.link-list` with one `li.link-row.linked-row` per linked
 * record (its title as a link, the detail, and an Unlink button when the
 * person can edit) or "No linked records"; then the link to the picker,
 * the unlink help, and the leave note. On a new record: only the
 * after-save note (M4 D-38).
 *
 * Each Unlink button submits the field's unlink form, which the record
 * page prints after the record form with id('unlink', key)
 * (docs/design/m4/record-controls.md section 2): `form` names it, `name`
 * is `target`, and `value` the linked record's id. The link to the picker
 * is described by the field's label, the `p.label` with id('f', key).
 *
 * Data: `key` (the field key: the id part of the label and of the unlink
 * form), `rows` (list of {path, title, detail (plain or null), target
 * (the linked record's id, int)}), `unlink` (bool: the person can edit, so
 * the Unlink buttons are shown and the unlink form exists), `linkPath`
 * (the picker's address, or null when the person cannot edit or the
 * record is new), `item` (the other table's item name, plain), `isNew`
 * (bool), `invalid` (optional bool: the field has an error text,
 * id('err', key), which the link to the picker then lists in
 * aria-describedby; P186). Every text is plain and escaped here; paths go through url().
 */

declare(strict_types=1);

?>
<?php if ($data['isNew'] === true) : ?>
<p class="help"><?= $this->t('components.link.after_save_many') ?></p>
<?php else : ?>
<?php if (count($data['rows']) > 0) : ?>
<ul class="link-list">
<?php foreach ($data['rows'] as $row) : ?>
<li class="link-row linked-row">
<div class="linked-main">
<a href="<?= $this->url($row['path']) ?>"><?= $this->e($row['title']) ?></a>
<?php if (is_string($row['detail'])) : ?>
<span class="link-detail"><?= $this->e($row['detail']) ?></span>
<?php endif; ?>
</div>
<?php if ($data['unlink'] === true) : ?>
<button class="button button-plain" type="submit" form="<?= $this->id('unlink', $data['key']) ?>" name="target" value="<?= $this->e($row['target']) ?>" aria-label="<?= $this->t('components.link.unlink_label', ['title' => $row['title']]) ?>"><?= $this->t('components.link.unlink') ?></button>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php else : ?>
<p class="static-value"><?= $this->t('input.links.none') ?></p>
<?php endif; ?>
<?php if (is_string($data['linkPath'])) : ?>
<div class="control-actions">
<?php if (count($data['rows']) > 0) : ?>
<?php if (($data['invalid'] ?? false) === true) : ?>
<a class="button button-strong" href="<?= $this->url($data['linkPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"><?= $this->t('components.link.add', ['item' => $data['item']]) ?></a>
<?php else : ?>
<a class="button button-strong" href="<?= $this->url($data['linkPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.link.add', ['item' => $data['item']]) ?></a>
<?php endif; ?>
<?php else : ?>
<?php if (($data['invalid'] ?? false) === true) : ?>
<a class="button button-strong" href="<?= $this->url($data['linkPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?> <?= $this->id('err', $data['key']) ?>"><?= $this->t('components.link.add_first') ?></a>
<?php else : ?>
<a class="button button-strong" href="<?= $this->url($data['linkPath']) ?>" aria-describedby="<?= $this->id('f', $data['key']) ?>"><?= $this->t('components.link.add_first') ?></a>
<?php endif; ?>
<?php endif; ?>
</div>
<p class="help"><?= $this->t('components.link.unlink_help', ['item' => $data['item']]) ?></p>
<?= $this->partial('leave-note') ?>
<?php endif; ?>
<?php endif; ?>
