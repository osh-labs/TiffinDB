<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of auto-number fields (docs/design/m1/schema-editor.md section
 * 7.3): the first number, chosen only when the field is made.
 *
 * Data: `mode`, `values` {opt_start (new)}, `errors`, `start` and `next`
 * (edit).
 */

declare(strict_types=1);

?>
<?php if ($data['mode'] === 'new') : ?>
<div class="field">
<label class="label" for="opt-start"><?= $this->t('schema.opt.start') ?></label>
<p class="help" id="opt-start-help"><?= $this->t('schema.opt.start_help') ?></p>
<?php if (is_string($data['errors']['opt-start'] ?? null)) : ?>
<p class="error-text" id="opt-start-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-start']]) ?></p>
<input class="input input-short" id="opt-start" type="number" name="opt_start" min="0" value="<?= $this->e($data['values']['opt_start']) ?>" aria-describedby="opt-start-help opt-start-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-short" id="opt-start" type="number" name="opt_start" min="0" value="<?= $this->e($data['values']['opt_start']) ?>" aria-describedby="opt-start-help" />
<?php endif; ?>
</div>
<?php else : ?>
<p><?= $this->t('schema.opt.start_fixed', ['start' => $data['start'], 'next' => $data['next']]) ?></p>
<?php endif; ?>
