<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The record table of the list view (docs/design/m2/list-and-search.md
 * section 2 item 6): a visually hidden caption, one header per column (the
 * sorted one with `aria-sort`), and one row per record whose first cell is
 * a row header holding the title link. Other cells show the value in
 * `cell` mode (M2 spec part 2 section 5.2); relation cells link up to
 * three linked records, then "and N more"; attachment cells count the
 * files; empty relation and attachment cells say "None".
 *
 * Data: `caption` (plain text); `columns` = list of {field (FieldDef),
 * header (plain text), numeric (bool), sort ('ascending', 'descending', or
 * null)}, the title column first; `rows` = list of {path, query, title
 * (plain text), cells}, where `cells` holds one entry per column after the
 * first: {field (FieldDef), value (canonical value), links (null, or list
 * of {path, table, id, title (plain text or null)}), moreLinks (int),
 * fileCount (null, or int)}; `itemNames` = table key => item name, for
 * links to untitled records ("Item 12").
 */

declare(strict_types=1);

?>
<div class="table-wrap">
<table class="data-table data-table-plain">
<caption class="visually-hidden"><?= $this->e($data['caption']) ?></caption>
<thead>
<tr>
<?php foreach ($data['columns'] as $column) : ?>
<th scope="col"<?php if ($column['numeric'] === true) : ?> class="num"<?php endif; ?><?php if ($column['sort'] === 'ascending') : ?> aria-sort="ascending"<?php elseif ($column['sort'] === 'descending') : ?> aria-sort="descending"<?php endif; ?>><?= $this->e($column['header']) ?></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($data['rows'] as $row) : ?>
<tr>
<th scope="row"><a class="cell-title" href="<?= $this->url($row['path'], $row['query']) ?>"><?= $this->e($row['title']) ?></a></th>
<?php foreach ($row['cells'] as $i => $cell) : ?>
<td<?php if ($data['columns'][$i + 1]['numeric'] === true) : ?> class="num"<?php endif; ?>><?php if (is_array($cell['links'] ?? null) && count($cell['links']) === 0) : ?><span class="cell-none"><?= $this->t('value.none') ?></span><?php elseif (is_array($cell['links'] ?? null)) : ?><?php foreach ($cell['links'] as $n => $link) : ?><?php if ($n > 0) : ?><?= $this->t('value.separator') ?><?php endif; ?><?php if (is_string($link['title'])) : ?><a href="<?= $this->url($link['path']) ?>"><?= $this->e($link['title']) ?></a><?php else : ?><a href="<?= $this->url($link['path']) ?>"><?= $this->t('value.untitled', ['Item' => $data['itemNames'][$link['table']] ?? '', 'id' => $link['id']]) ?></a><?php endif; ?><?php endforeach; ?><?php if (($cell['moreLinks'] ?? 0) > 0) : ?> <?= $this->t('value.more_links', ['count' => $this->number($cell['moreLinks'])]) ?><?php endif; ?><?php elseif (is_int($cell['fileCount'] ?? null) && $cell['fileCount'] > 0) : ?><?= $this->t('value.files', ['count' => $cell['fileCount']]) ?><?php elseif (is_int($cell['fileCount'] ?? null)) : ?><span class="cell-none"><?= $this->t('value.none') ?></span><?php else : ?><?= $this->value($cell['field'], $cell['value'], 'cell') ?><?php endif; ?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
