<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Delete a field: the confirmation page (docs/design/m1/schema-editor.md
 * section 10). The layout prints the breadcrumb before this.
 *
 * Data: `field` {name}, `links` (bool: a link or file field), `records`
 * (counted text), `reverse` ({field, table} or null), `action` and
 * `cancel` (application paths).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('schema.field.delete_confirm.title', ['field' => $data['field']['name']]) ?></h1>
<?php if ($data['links'] === true) : ?>
<p><?= $this->t('schema.field.delete_confirm.body_links') ?></p>
<?php else : ?>
<p><?= $this->t('schema.field.delete_confirm.body', ['records' => $data['records']]) ?></p>
<?php endif; ?>
<?php if (is_array($data['reverse'])) : ?>
<p><?= $this->t('schema.field.delete_confirm.reverse', ['other' => $data['reverse']['field'], 'table' => $data['reverse']['table']]) ?></p>
<?php endif; ?>
<p><?= $this->t('schema.delete.copy') ?></p>
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'schema.field.delete', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
