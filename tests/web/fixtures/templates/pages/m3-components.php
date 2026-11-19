<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: renders the shared M3 partials (M3 spec part 2 section
 * 5.1) the way the screen packages will. ComponentsTest copies it next to
 * the real templates and renders it through View; TemplateRulesTest checks
 * it follows the rules. The file field sits in an upload form (section
 * 5.2).
 *
 * Data (each part optional): `steps`, `file`, `facts`, `warnings`,
 * `progress`, `continue` (each the data of the partial of that name).
 */

declare(strict_types=1);

?>
<h1><?= $this->t('app.name') ?></h1>
<?php if (is_array($data['steps'] ?? null)) : ?>
<?= $this->partial('steps', $data['steps']) ?>
<?php endif; ?>
<?php if (is_array($data['file'] ?? null)) : ?>
<form method="post" action="<?= $this->url('/projects') ?>" enctype="multipart/form-data">
<?= $this->partial('csrf') ?>
<?= $this->partial('file-field', $data['file']) ?>
<button class="button button-primary" type="submit"><?= $this->t('app.name') ?></button>
</form>
<?php endif; ?>
<?php if (is_array($data['facts'] ?? null)) : ?>
<?= $this->partial('facts', $data['facts']) ?>
<?php endif; ?>
<?php if (is_array($data['warnings'] ?? null)) : ?>
<?= $this->partial('warning-panel', $data['warnings']) ?>
<?php endif; ?>
<?php if (is_array($data['progress'] ?? null)) : ?>
<?= $this->partial('progress', $data['progress']) ?>
<?php endif; ?>
<?php if (is_array($data['continue'] ?? null)) : ?>
<?= $this->partial('continue-form', $data['continue']) ?>
<?php endif; ?>
