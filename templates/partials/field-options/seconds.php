<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of time, date and time, and duration fields
 * (docs/design/m1/schema-editor.md section 7.3): seconds shown or not.
 *
 * Data: `values` {opt_seconds ('1' or '')}, `errors`.
 */

declare(strict_types=1);

?>
<div class="field">
<label class="check">
<?php if ($data['values']['opt_seconds'] === '1') : ?>
<input id="opt-seconds" type="checkbox" name="opt_seconds" value="1" checked="checked" />
<?php else : ?>
<input id="opt-seconds" type="checkbox" name="opt_seconds" value="1" />
<?php endif; ?>
<span><?= $this->t('schema.opt.seconds') ?></span>
</label>
<?php if (is_string($data['errors']['opt-seconds'] ?? null)) : ?>
<p class="error-text" id="opt-seconds-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-seconds']]) ?></p>
<?php endif; ?>
</div>
