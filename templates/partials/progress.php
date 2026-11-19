<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Progress of a long task (M3 spec part 2 sections 5.1 and 9.1;
 * docs/design/m3/components.md section 8): a label, a native progress
 * element labelled by it, and the status as a live region. With a total,
 * the element has max and value; without one (the total is not known
 * yet) it has neither, so it is shown as indeterminate. One per page (its
 * label id is fixed).
 *
 * Data: `label` (string key), `labelParams` (its parameters, such as the
 * file name), `done` (int), `total` (int or null), `status` (string key),
 * `statusParams` (its parameters).
 */

declare(strict_types=1);

?>
<div class="progress">
<p id="progress-label"><?= $this->t($data['label'], $data['labelParams'] ?? []) ?></p>
<?php if (is_int($data['total'] ?? null)) : ?>
<progress aria-labelledby="progress-label" max="<?= $this->e($data['total']) ?>" value="<?= $this->e($data['done']) ?>"></progress>
<?php else : ?>
<progress aria-labelledby="progress-label"></progress>
<?php endif; ?>
<p class="progress-status" role="status"><?= $this->t($data['status'], $data['statusParams'] ?? []) ?></p>
</div>
