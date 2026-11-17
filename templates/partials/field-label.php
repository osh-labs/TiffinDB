<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The inner text of a field's label (M1 spec part 2 section 4.4; P50): the
 * label text and, for optional fields, the "(optional)" marker. The
 * calling template writes the `label.label` element with its literal `for`
 * around it (ids cannot come from a partial).
 *
 * Data: `key` (string key of the label text), `optional` (bool).
 */

declare(strict_types=1);

?>
<?= $this->t($data['key']) ?><?php if (($data['optional'] ?? false) === true) : ?> <span class="label-optional"><?= $this->t('form.optional') ?></span><?php endif; ?>
