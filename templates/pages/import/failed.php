<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Import failed (docs/design/m3/import.md section 8). Data: `reason`
 * (plain text), `cancel` (the Start again route).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('import.failed.title') ?></h1>
<p><?= $this->t('import.failed.body') ?></p>
<p><?= $this->e($data['reason']) ?></p>
<form class="inline-form" method="post" action="<?= $this->url($data['cancel']) ?>">
<?= $this->partial('csrf') ?>
<button class="button button-primary button-large" type="submit"><?= $this->t('import.failed.again') ?></button>
</form>
