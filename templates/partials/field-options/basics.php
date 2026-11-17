<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The parts every field form shares (docs/design/m1/schema-editor.md
 * sections 7.2 and 8): name, hint, and, unless the type is never
 * required, "People must fill this in".
 *
 * Data: `values` {name, help, required ('1' or '')}, `errors` (element
 * id => plain text), `showRequired` (bool).
 */

declare(strict_types=1);

?>
<div class="field">
<label class="label" for="field-name"><?= $this->t('schema.field.name') ?></label>
<p class="help" id="field-name-help"><?= $this->t('schema.field.name_help') ?></p>
<?php if (is_string($data['errors']['field-name'] ?? null)) : ?>
<p class="error-text" id="field-name-error"><?= $this->partial('field-error', ['text' => $data['errors']['field-name']]) ?></p>
<input class="input" id="field-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="field-name-help field-name-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="field-name" type="text" name="name" value="<?= $this->e($data['values']['name']) ?>" autocomplete="off" required="required" aria-describedby="field-name-help" />
<?php endif; ?>
</div>
<div class="field">
<label class="label" for="field-help"><?= $this->partial('field-label', ['key' => 'schema.field.help', 'optional' => true]) ?></label>
<p class="help" id="field-help-help"><?= $this->t('schema.field.help_help') ?></p>
<?php if (is_string($data['errors']['field-help'] ?? null)) : ?>
<p class="error-text" id="field-help-error"><?= $this->partial('field-error', ['text' => $data['errors']['field-help']]) ?></p>
<input class="input" id="field-help" type="text" name="help" value="<?= $this->e($data['values']['help']) ?>" autocomplete="off" aria-describedby="field-help-help field-help-error" aria-invalid="true" />
<?php else : ?>
<input class="input" id="field-help" type="text" name="help" value="<?= $this->e($data['values']['help']) ?>" autocomplete="off" aria-describedby="field-help-help" />
<?php endif; ?>
</div>
<?php if ($data['showRequired'] === true) : ?>
<label class="check">
<?php if ($data['values']['required'] === '1') : ?>
<input id="field-required" type="checkbox" name="required" value="1" checked="checked" />
<?php else : ?>
<input id="field-required" type="checkbox" name="required" value="1" />
<?php endif; ?>
<span><?= $this->t('schema.field.required') ?></span>
</label>
<?php endif; ?>
