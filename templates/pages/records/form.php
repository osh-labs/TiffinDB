<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The record form and the new record form (docs/design/m2/record.md
 * sections 2 and 3). The layout prints the breadcrumb, the flash notice,
 * and the error summary before this.
 *
 * Data: `heading` (plain text), `lead` (plain text or null; null on a new
 * record), `isNew`, `nav` (null, or {previous, next} each null or {path,
 * label}), `state` (the list's view state, carried on every address),
 * `action` (path the form posts to), `version` (the version token; not
 * printed on a new record), `nodes` (the bound form, for the record-form
 * partials), `list` (path of the list), `cancelLabel` (plain text, the
 * shared "Cancel"), `trash` (path of the trash action, or null), `meta`
 * (null, or {added, addedBy, changed, changedBy} plain text, names null
 * when unknown).
 *
 * The Save button sits outside the form and names it with
 * form="record-form"; the trash form is a separate form (no nested forms).
 */

declare(strict_types=1);

?>
<div class="page-head">
<div>
<h1><?= $this->e($data['heading']) ?></h1>
<?php if ($data['isNew'] === true) : ?>
<p class="lead"><?= $this->t('records.new.lead') ?></p>
<?php elseif (is_string($data['lead'])) : ?>
<p class="lead"><?= $this->e($data['lead']) ?></p>
<?php endif; ?>
</div>
<?php if (is_array($data['nav'])) : ?>
<div class="record-nav">
<?php if (is_array($data['nav']['previous'])) : ?>
<a class="button" href="<?= $this->url($data['nav']['previous']['path'], $data['state']) ?>" rel="prev" aria-label="<?= $this->e($data['nav']['previous']['label']) ?>"><?= $this->t('records.previous') ?></a>
<?php else : ?>
<span class="button" aria-disabled="true"><?= $this->t('records.previous') ?></span>
<?php endif; ?>
<?php if (is_array($data['nav']['next'])) : ?>
<a class="button" href="<?= $this->url($data['nav']['next']['path'], $data['state']) ?>" rel="next" aria-label="<?= $this->e($data['nav']['next']['label']) ?>"><?= $this->t('records.next') ?></a>
<?php else : ?>
<span class="button" aria-disabled="true"><?= $this->t('records.next') ?></span>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<form id="record-form" class="record-form" method="post" action="<?= $this->url($data['action'], $data['state']) ?>">
<?= $this->partial('csrf') ?>
<?php if ($data['isNew'] !== true) : ?>
<input type="hidden" name="version" value="<?= $this->e($data['version']) ?>" />
<?php endif; ?>
<?php foreach ($data['nodes'] as $node) : ?>
<?php if ($node['kind'] === 'section') : ?>
<?= $this->partial('record-form/section', ['node' => $node]) ?>
<?php elseif ($node['kind'] === 'field') : ?>
<?= $this->partial('record-form/field', ['node' => $node]) ?>
<?php else : ?>
<?= $this->partial('record-form/static', ['node' => $node]) ?>
<?php endif; ?>
<?php endforeach; ?>
</form>
<div class="record-actions">
<div class="record-actions-main">
<?php if ($data['isNew'] === true) : ?>
<button class="button button-primary button-large" type="submit" form="record-form"><?= $this->t('records.create') ?></button>
<a class="button button-large" href="<?= $this->url($data['list'], $data['state']) ?>"><?= $this->e($data['cancelLabel']) ?></a>
<?php else : ?>
<button class="button button-primary button-large" type="submit" form="record-form"><?= $this->t('records.save') ?></button>
<a class="button button-large" href="<?= $this->url($data['list'], $data['state']) ?>"><?= $this->t('records.back') ?></a>
<?php endif; ?>
</div>
<?php if (is_string($data['trash'])) : ?>
<form class="trash-form" method="post" action="<?= $this->url($data['trash'], $data['state']) ?>">
<?= $this->partial('csrf') ?>
<button class="button button-danger button-large" type="submit"><?= $this->t('records.trash') ?></button>
</form>
<?php endif; ?>
</div>
<?php if (is_array($data['meta'])) : ?>
<p class="record-meta"><?php if (is_string($data['meta']['addedBy'])) : ?><?= $this->t('records.meta.added', ['date' => $data['meta']['added'], 'name' => $data['meta']['addedBy']]) ?><?php else : ?><?= $this->t('records.meta.added_unknown', ['date' => $data['meta']['added']]) ?><?php endif; ?> <?php if (is_string($data['meta']['changedBy'])) : ?><?= $this->t('records.meta.changed', ['date' => $data['meta']['changed'], 'name' => $data['meta']['changedBy']]) ?><?php else : ?><?= $this->t('records.meta.changed_unknown', ['date' => $data['meta']['changed']]) ?><?php endif; ?> <?= $this->t('records.meta.trash_note') ?></p>
<?php endif; ?>
