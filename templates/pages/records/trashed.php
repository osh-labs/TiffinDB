<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A record in the trash (docs/design/m2/record.md section 4): what
 * happened, a Restore button, and the way back to the list.
 *
 * Data: `item` (the table's item name), `record` (the record's title,
 * plain text), `date` (when it was moved, plain text), `restore` (path of
 * the restore action), `list` (path of the list), `state` (the list's
 * view state).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('records.trashed.title', ['item' => $data['item']]) ?></h1>
<p><?= $this->t('records.trashed.body', ['title' => $data['record'], 'date' => $data['date']]) ?></p>
<div class="form-actions">
<form method="post" action="<?= $this->url($data['restore'], $data['state']) ?>">
<?= $this->partial('csrf') ?>
<button class="button button-primary button-large" type="submit"><?= $this->t('records.restore') ?></button>
</form>
<a class="button button-large" href="<?= $this->url($data['list'], $data['state']) ?>"><?= $this->t('records.back') ?></a>
</div>
