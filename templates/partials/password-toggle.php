<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The "Show password" row (M1 spec part 2 sections 4.4 and 6; P46). It is
 * hidden until public/assets/js/show-password.js removes `hidden`, so
 * without JavaScript it never appears and the form works unchanged. The
 * page must set `script` to `show-password`.
 *
 * Data: `plural` (bool, default false): "Show passwords" for forms with
 * more than one password input.
 */

declare(strict_types=1);

?>
<div class="check show-password" hidden="hidden">
<label class="check">
<input type="checkbox" data-show-password="data-show-password" />
<?php if (($data['plural'] ?? false) === true) : ?>
<span><?= $this->t('form.show_passwords') ?></span>
<?php else : ?>
<span><?= $this->t('form.show_password') ?></span>
<?php endif; ?>
</label>
</div>
