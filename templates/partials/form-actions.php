<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A form's submit button and optional Cancel link (M1 spec part 2 section
 * 4.4; P50). Every form whose actions are one submit button and an
 * optional Cancel link uses it.
 *
 * Data: `submit` (string key of the button text), `danger` (bool: the
 * destructive style instead of the primary one), `cancel` (application
 * path for Cancel, or null for none).
 */

declare(strict_types=1);

?>
<div class="form-actions">
<?php if ($data['danger'] === true) : ?>
<button class="button button-large button-danger" type="submit"><?= $this->t($data['submit']) ?></button>
<?php else : ?>
<button class="button button-large button-primary" type="submit"><?= $this->t($data['submit']) ?></button>
<?php endif; ?>
<?php if (is_string($data['cancel'] ?? null)) : ?>
<a class="form-cancel" href="<?= $this->url($data['cancel']) ?>"><?= $this->t('form.cancel') ?></a>
<?php endif; ?>
</div>
