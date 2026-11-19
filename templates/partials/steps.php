<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The step indicator of the import screens (M3 spec part 2 section 5.1;
 * docs/design/m3/components.md section 2): an ordered list named by
 * `label`, one item per step with its visible state ("Step 1, done",
 * "Step 2, now", "Step 3") and its name. The current step carries
 * aria-current="step"; the words, not colour alone, carry the state.
 *
 * Data: `label` (string key of the list's accessible name), `steps` (list
 * of {label: string key, state: 'done'|'now'|'next'}, in order; any other
 * state is drawn as 'next').
 */

declare(strict_types=1);

?>
<ol class="steps" aria-label="<?= $this->t($data['label']) ?>">
<?php foreach ($data['steps'] as $index => $step) : ?>
<?php if ($step['state'] === 'done') : ?>
<li class="step step-done"><span class="step-number"><?= $this->t('steps.done', ['n' => $index + 1]) ?></span> <span class="step-label"><?= $this->t($step['label']) ?></span></li>
<?php elseif ($step['state'] === 'now') : ?>
<li class="step step-now" aria-current="step"><span class="step-number"><?= $this->t('steps.now', ['n' => $index + 1]) ?></span> <span class="step-label"><?= $this->t($step['label']) ?></span></li>
<?php else : ?>
<li class="step step-next"><span class="step-number"><?= $this->t('steps.next', ['n' => $index + 1]) ?></span> <span class="step-label"><?= $this->t($step['label']) ?></span></li>
<?php endif; ?>
<?php endforeach; ?>
</ol>
