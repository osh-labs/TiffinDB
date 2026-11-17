<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Options of file fields (docs/design/m1/schema-editor.md section 7.3):
 * pictures only or not.
 *
 * Data: `values` {opt_images_only ('1' or '')}, `errors`.
 */

declare(strict_types=1);

?>
<div class="field">
<label class="check">
<?php if ($data['values']['opt_images_only'] === '1') : ?>
<input id="opt-images-only" type="checkbox" name="opt_images_only" value="1" checked="checked" aria-describedby="opt-images-only-help" />
<?php else : ?>
<input id="opt-images-only" type="checkbox" name="opt_images_only" value="1" aria-describedby="opt-images-only-help" />
<?php endif; ?>
<span><?= $this->t('schema.opt.images_only') ?></span>
</label>
<p class="help" id="opt-images-only-help"><?= $this->t('schema.opt.images_only_help') ?></p>
<?php if (is_string($data['errors']['opt-images-only'] ?? null)) : ?>
<p class="error-text" id="opt-images-only-error"><?= $this->partial('field-error', ['text' => $data['errors']['opt-images-only']]) ?></p>
<?php endif; ?>
</div>
