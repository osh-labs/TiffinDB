<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: footer partial.
 */

declare(strict_types=1);

?>
<footer><span><?= $this->t('shell.footer.licence') ?></span> <a href="<?= $this->e($data['sourceUrl']) ?>" rel="noopener noreferrer"><?= $this->t('shell.footer.source') ?></a></footer>
