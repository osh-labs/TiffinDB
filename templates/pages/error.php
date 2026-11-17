<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Error page (M0 spec section 6.5; M1 spec part 2 section 2.4): title, one
 * sentence, and a link to the start page, inside the signed-in shell when
 * a session exists and the plain shell otherwise.
 *
 * Data from the kernel: `status`, `title` (plain text, used by the layout),
 * `titleKey` and `bodyKey` (string keys such as error.<status>.title and
 * .body, error.csrf.*, error.busy.*, error.unavailable.<reason>.*).
 */

declare(strict_types=1);

?>
<div class="error-head">
<h1><?= $this->t($data['titleKey']) ?></h1>
<p class="lead"><?= $this->t($data['bodyKey']) ?></p>
</div>
<p class="back"><a class="back-link" href="<?= $this->url('/') ?>"><?= $this->t('error.back') ?></a></p>
