<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: one form whose actions row is the form-actions partial,
 * rendered with the data the test gives. ShellTest copies it next to the
 * real templates and renders it through View.
 *
 * Data: `actions` (the form-actions partial's data, passed through).
 */

declare(strict_types=1);

?>
<form class="form" method="post" action="<?= $this->url('/projects') ?>">
<?= $this->partial('csrf') ?>
<?= $this->partial('form-actions', $data['actions']) ?>
</form>
