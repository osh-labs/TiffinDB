<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of text and long text fields (docs/design/m1/schema-editor.md
 * section 7.3): the longest allowed length.
 *
 * Data: `values` {opt_max_length}, `errors`, `max` (the type's limit).
 */

declare(strict_types=1);

?>
<div class="field">
<label class="label" for="opt-max-length"><?= $this->t('schema.opt.max_length') ?></label>
<?php if (is_string($data['errors']['opt-max-length'] ?? null)) : ?>
<p class="error-text" id="opt-max-length-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-max-length']]) ?></p>
<input class="input input-short" id="opt-max-length" type="number" name="opt_max_length" min="1" max="<?= $this->e($data['max']) ?>" value="<?= $this->e($data['values']['opt_max_length']) ?>" aria-describedby="opt-max-length-error" aria-invalid="true" />
<?php else : ?>
<input class="input input-short" id="opt-max-length" type="number" name="opt_max_length" min="1" max="<?= $this->e($data['max']) ?>" value="<?= $this->e($data['values']['opt_max_length']) ?>" />
<?php endif; ?>
</div>
