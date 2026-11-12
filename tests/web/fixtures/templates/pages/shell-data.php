<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: reports what the page template receives in `shell`, and
 * prints the csrf() and count() helpers.
 */

declare(strict_types=1);

?>
<p id="shell"><?= $this->e(is_array($data['shell']) ? implode(',', array_keys($data['shell'])) . '|' . $data['shell']['displayName'] : 'null') ?></p>
<input type="hidden" name="_csrf" value="<?= $this->csrf() ?>" />
<p id="count"><?= $this->count('count.test', $data['n']) ?></p>
