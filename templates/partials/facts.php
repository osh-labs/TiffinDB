<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * A panel of facts (M3 spec part 2 section 5.1; docs/design/m3/
 * components.md sections 1 and 3): a section named by its heading, then a
 * description list of term and value pairs. Values are plain text (file
 * names, plugin texts), always escaped.
 *
 * Data: `heading` (string key), `id` (the heading's id part: the heading
 * is id('i', id), for example `i-file`), `items` (list of {term: string
 * key, value: plain text}).
 */

declare(strict_types=1);

?>
<section class="panel" aria-labelledby="<?= $this->id('i', $data['id']) ?>">
<h2 id="<?= $this->id('i', $data['id']) ?>"><?= $this->t($data['heading']) ?></h2>
<dl class="facts">
<?php foreach ($data['items'] as $item) : ?>
<div>
<dt><?= $this->t($item['term']) ?></dt>
<dd><?= $this->e($item['value']) ?></dd>
</div>
<?php endforeach; ?>
</dl>
</section>
