<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Error page (M0 spec section 6.5): title, one sentence, and a link to the
 * start page, inside the normal shell.
 *
 * Data from the kernel: `status`, `title` (plain text, used by the layout),
 * `titleKey` and `bodyKey` (string keys error.<status>.title and .body).
 */

declare(strict_types=1);

?>
<h1><?= $this->t($data['titleKey']) ?></h1>
<p class="lead"><?= $this->t($data['bodyKey']) ?></p>
<p class="back"><a class="back-link" href="<?= $this->url('/') ?>"><?= $this->t('error.back') ?></a></p>
