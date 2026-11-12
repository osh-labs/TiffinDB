<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: prints part of its output, then fails.
 */

declare(strict_types=1);

?>
<p>PARTIAL-OUTPUT-MARKER</p>
<?= $this->partial('item', ['label' => 'NESTED-PARTIAL-MARKER']) ?>
<?php throw new RuntimeException('Template failure at /fixture/secret/path.php'); ?>
