<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Restore a snapshot: the confirmation page (docs/design/m7/snapshots.md
 * section 3), for Admins only (the controller asks the core first). The
 * layout prints the breadcrumb and, after a refusal (422), the error
 * summary before this; then `counts`, `action`, and `cancel` are null and
 * no form is drawn.
 *
 * Data: `project` (its name), `snapshot` ({when (text), datetime (UTC
 * timestamp), reason (text)}, or null when it is no longer listed),
 * `counts` ({then, now, filesRemoved, filesMissing (ints), older (bool)},
 * or null), `action` and `cancel` (application paths, or null).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('snapshots.restore.title') ?></h1>
<?php if (is_array($data['snapshot'])) : ?>
<dl class="facts">
<div>
<dt><?= $this->t('snapshots.restore.saved') ?></dt>
<dd><time datetime="<?= $this->e($data['snapshot']['datetime']) ?>"><?= $this->e($data['snapshot']['when']) ?></time></dd>
</div>
<div>
<dt><?= $this->t('snapshots.restore.reason') ?></dt>
<dd><?= $this->e($data['snapshot']['reason']) ?></dd>
</div>
<?php if (is_array($data['counts'])) : ?>
<div>
<dt><?= $this->t('snapshots.restore.records') ?></dt>
<dd><?= $this->t('snapshots.restore.records_value', ['then' => $data['counts']['then'], 'now' => $data['counts']['now']]) ?></dd>
</div>
<?php endif; ?>
</dl>
<?php endif; ?>
<?php if (is_array($data['counts']) && is_string($data['action']) && is_string($data['cancel'])) : ?>
<p><?= $this->t('snapshots.restore.body', ['project' => $data['project']]) ?></p>
<?php if ($data['counts']['filesRemoved'] > 0) : ?>
<p><?= $this->t('snapshots.restore.files_removed', ['count' => $data['counts']['filesRemoved']]) ?></p>
<?php endif; ?>
<?php if ($data['counts']['filesMissing'] > 0) : ?>
<p><?= $this->t('snapshots.restore.files_missing', ['count' => $data['counts']['filesMissing']]) ?></p>
<?php endif; ?>
<?php if ($data['counts']['older'] === true) : ?>
<p class="help"><?= $this->t('snapshots.restore.older') ?></p>
<?php endif; ?>
<p class="help"><?= $this->t('snapshots.restore.others') ?></p>
<div class="danger-zone">
<form class="form" method="post" action="<?= $this->url($data['action']) ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', ['submit' => 'snapshots.restore.submit', 'danger' => true, 'cancel' => $data['cancel']]) ?>
</form>
</div>
<?php endif; ?>
