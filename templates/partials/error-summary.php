<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Error summary (M1 spec part 2 sections 4.4 and 4.5): announced as an
 * alert, with a heading counting the problems and a list linking each
 * problem to its input. The layout prints it when the page data has a
 * non-empty `problems` list.
 *
 * Data: `problems`, a list of {field: the input's element id or null,
 * text: plain text}. A problem without a field is listed without a link.
 */

declare(strict_types=1);

?>
<div class="error-summary" role="alert" tabindex="-1">
<h2 class="error-summary-title"><?= $this->count('count.problems', count($data['problems'])) ?></h2>
<ul>
<?php foreach ($data['problems'] as $problem) : ?>
<?php if (is_string($problem['field'] ?? null)) : ?>
<li><a href="<?= $this->url('#' . $problem['field']) ?>"><?= $this->e($problem['text']) ?></a></li>
<?php else : ?>
<li><?= $this->e($problem['text']) ?></li>
<?php endif; ?>
<?php endforeach; ?>
</ul>
</div>
