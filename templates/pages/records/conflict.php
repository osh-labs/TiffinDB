<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The edit conflict (docs/design/m2/record.md section 5), status 409: who
 * saved and when, the fields that differ, "Keep the saved version" (a
 * link back to the record), and "Save my version anyway" (the re-post
 * form: the record's current version and the text typed, one hidden
 * f_<key> per field the original form posted; never the old CSRF value,
 * since the csrf partial prints the session's own).
 *
 * Data: `item`, `name` (plain text or null when unknown), `when` (plain
 * text), `rows` (list of {label, field (FieldDef), saved (canonical
 * value), savedEmpty, yours (plain text), yoursEmpty, multiline}), `path`
 * (the record's path), `state` (the list's view state), `version` (the
 * current version token), `hidden` (list of {name, value}).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('records.conflict.title', ['item' => $data['item']]) ?></h1>
<?php if (is_string($data['name'])) : ?>
<p><?= $this->t('records.conflict.body', ['name' => $data['name'], 'when' => $data['when']]) ?></p>
<?php else : ?>
<p><?= $this->t('records.conflict.body_unknown', ['when' => $data['when']]) ?></p>
<?php endif; ?>
<?php if (count($data['rows']) > 0) : ?>
<div class="table-wrap">
<table class="data-table compare-table">
<caption class="visually-hidden"><?= $this->t('records.conflict.caption') ?></caption>
<thead>
<tr>
<th scope="col"><?= $this->t('records.conflict.col.field') ?></th>
<th scope="col"><?= $this->t('records.conflict.col.saved') ?></th>
<th scope="col"><?= $this->t('records.conflict.col.yours') ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($data['rows'] as $row) : ?>
<tr>
<th scope="row"><?= $this->e($row['label']) ?></th>
<?php if ($row['savedEmpty'] === true) : ?>
<td><?= $this->t('records.conflict.empty') ?></td>
<?php elseif ($row['multiline'] === true) : ?>
<td><span class="text-multiline"><?= $this->value($row['field'], $row['saved']) ?></span></td>
<?php else : ?>
<td><?= $this->value($row['field'], $row['saved']) ?></td>
<?php endif; ?>
<?php if ($row['yoursEmpty'] === true) : ?>
<td><?= $this->t('records.conflict.empty') ?></td>
<?php elseif ($row['multiline'] === true) : ?>
<td><span class="text-multiline"><?= $this->e($row['yours']) ?></span></td>
<?php else : ?>
<td><?= $this->e($row['yours']) ?></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else : ?>
<p><?= $this->t('records.conflict.same') ?></p>
<?php endif; ?>
<div class="form-actions">
<a class="button button-primary button-large" href="<?= $this->url($data['path'], $data['state']) ?>"><?= $this->t('records.conflict.keep') ?></a>
<form method="post" action="<?= $this->url($data['path'], $data['state']) ?>">
<?= $this->partial('csrf') ?>
<input type="hidden" name="version" value="<?= $this->e($data['version']) ?>" />
<?php foreach ($data['hidden'] as $field) : ?>
<input type="hidden" name="<?= $this->e($field['name']) ?>" value="<?= $this->e($field['value']) ?>" />
<?php endforeach; ?>
<button class="button button-danger button-large" type="submit"><?= $this->t('records.conflict.overwrite') ?></button>
</form>
</div>
