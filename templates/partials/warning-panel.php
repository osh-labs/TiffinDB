<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A panel of warnings (M3 spec part 2 section 5.1; docs/design/m3/
 * components.md section 4): a section named by its heading, then one list
 * item per warning. One per page (its heading id is fixed).
 *
 * Data: `heading` (string key), `headingParams` (its parameters), `items`
 * (list of {key: string key, params: its parameters}).
 */

declare(strict_types=1);

?>
<section class="warning-panel" aria-labelledby="warning-panel-heading">
<h2 id="warning-panel-heading"><?= $this->t($data['heading'], $data['headingParams'] ?? []) ?></h2>
<ul>
<?php foreach ($data['items'] as $item) : ?>
<li><?= $this->t($item['key'], $item['params'] ?? []) ?></li>
<?php endforeach; ?>
</ul>
</section>
