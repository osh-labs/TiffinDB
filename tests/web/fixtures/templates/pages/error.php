<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: error page.
 */

declare(strict_types=1);

?>
<h1><?= $this->t($data['titleKey']) ?></h1>
<p><?= $this->t($data['bodyKey']) ?></p>
<p><a href="<?= $this->url('/') ?>"><?= $this->t('error.back') ?></a></p>
