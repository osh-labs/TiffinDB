<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The form tools navigation (docs/design/m5/components.md section 3),
 * under the page head of the editor, source, upload, and reset pages:
 * `nav.form-tabs` labelled "Form tools" holding Arrange, Source, Upload a
 * form file, Download the form (a plain link to the download, never
 * current), and Back to the standard form. The current page's link has
 * `aria-current="page"`.
 *
 * Data: `base` (the table's path, `/projects/{p}/tables/{t}`), `current`
 * (`arrange`, `source`, `upload`, or `reset`).
 */

declare(strict_types=1);

?>
<nav class="form-tabs" aria-label="<?= $this->t('forms.tabs.label') ?>">
<ul class="form-tabs-list">
<?php if ($data['current'] === 'arrange') : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form') ?>" aria-current="page"><?= $this->t('forms.tabs.arrange') ?></a></li>
<?php else : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form') ?>"><?= $this->t('forms.tabs.arrange') ?></a></li>
<?php endif; ?>
<?php if ($data['current'] === 'source') : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/source') ?>" aria-current="page"><?= $this->t('forms.tabs.source') ?></a></li>
<?php else : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/source') ?>"><?= $this->t('forms.tabs.source') ?></a></li>
<?php endif; ?>
<?php if ($data['current'] === 'upload') : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/upload') ?>" aria-current="page"><?= $this->t('forms.tabs.upload') ?></a></li>
<?php else : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/upload') ?>"><?= $this->t('forms.tabs.upload') ?></a></li>
<?php endif; ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/download') ?>"><?= $this->t('forms.tabs.download') ?></a></li>
<?php if ($data['current'] === 'reset') : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/reset') ?>" aria-current="page"><?= $this->t('forms.tabs.reset') ?></a></li>
<?php else : ?>
<li><a class="form-tab" href="<?= $this->url($data['base'] . '/form/reset') ?>"><?= $this->t('forms.tabs.reset') ?></a></li>
<?php endif; ?>
</ul>
</nav>
