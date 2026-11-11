<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: reads a key the controller did not pass (a PHP warning).
 */

declare(strict_types=1);

?>
<p>WARNING-OUTPUT-MARKER</p>
<p><?= $this->e($data['missing']) ?></p>
