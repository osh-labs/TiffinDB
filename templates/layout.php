<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The application shell (M0 spec section 9.1; M1 spec part 2 section 4.1):
 * document head, skip link, then one of two shells.
 *
 * - Signed-in shell (`shell` is an array from Shell::toArray()): top bar,
 *   then `main` in the shape `layout` names (M2 spec part 2 section 3.2):
 *   absent `main.page`; `list` `div.app-columns` holding the sidebar
 *   (`sidebar`, from Sidebar::build()) and `main.page.page-list`; `record`,
 *   `record-narrow`, and `settings` the record and settings widths; `io`
 *   the import, export, archive, and plugins pages (`main.page.page-io`,
 *   M3 spec part 2 section 4); `picker`, `collection`, and `trash` the
 *   M4 relation picker, collection editor and delete confirmation, and
 *   trash pages (`main.page.page-picker`, `.page-collection`,
 *   `.page-trash`, docs/design/m4/components.md; P184 to P186); `form` and
 *   `form-narrow` the M5 form editor and source pages and the upload and
 *   reset pages (`main.page.page-form`, `.page-form.page-form-narrow`,
 *   docs/design/m5/components.md section 8); then the footer, with the
 *   About link (M7 screens spec section 5).
 * - Plain shell (`shell` null): lockup header, `main.page.page-plain` (or
 *   `main.page` when `wide` is true), centred footer.
 *
 * In both, `main` starts with what every page shares, in this order: the
 * breadcrumb (`breadcrumb`), the flash notice (signed in only), and the
 * error summary (`problems`); then the page (the `content` slot). Pages do
 * not print these themselves. A flash notice whose parameters hold `undo`
 * (a restore path Shell has checked) gets the Undo form.
 *
 * The head links the favicon and the web manifest on every page (M7
 * screens spec section 3).
 *
 * Data (see View for the full contract): `title`, `documentTitle`,
 * `hasError`, `sourceUrl`, `shell`, `wide`, `script` (`show-password`,
 * `settings-preview`, or `import-continue`, M3 spec part 2 section 7;
 * M4 adds none, P185),
 * `layout`, `sidebar`, `search` (the top bar's project search),
 * `breadcrumb`, `problems`. Output is HTML5 in XML-well-formed syntax (ADR 0008
 * decision 4).
 */

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en"<?= $this->rootClassAttribute() ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php if ($data['hasError'] === true) : ?>
<title><?= $this->t('form.errors.page_title', ['title' => $data['documentTitle']]) ?></title>
<?php elseif (($data['title'] ?? null) === null) : ?>
<title><?= $this->t('app.name') ?></title>
<?php else : ?>
<title><?= $this->t('shell.title', ['title' => $data['title']]) ?></title>
<?php endif; ?>
<link rel="stylesheet" href="<?= $this->asset('css/fonts.css') ?>" />
<link rel="stylesheet" href="<?= $this->asset('css/tokens.css') ?>" />
<link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>" />
<link rel="icon" href="<?= $this->asset('icons/favicon.svg') ?>" type="image/svg+xml" />
<link rel="manifest" href="<?= $this->asset('app.webmanifest') ?>" />
<?php if (($data['script'] ?? null) === 'show-password') : ?>
<script src="<?= $this->asset('js/show-password.js') ?>" defer="defer"></script>
<?php elseif (($data['script'] ?? null) === 'settings-preview') : ?>
<script src="<?= $this->asset('js/settings-preview.js') ?>" defer="defer"></script>
<?php elseif (($data['script'] ?? null) === 'import-continue') : ?>
<script src="<?= $this->asset('js/import-continue.js') ?>" defer="defer"></script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main"><?= $this->t('shell.skip') ?></a>
<div class="app">
<?php if (is_array($data['shell'] ?? null)) : ?>
<?= $this->partial('top-bar', ['shell' => $data['shell'], 'search' => $data['search'] ?? null]) ?>
<?php if (($data['layout'] ?? null) === 'list' && is_array($data['sidebar'] ?? null)) : ?>
<div class="app-columns">
<?= $this->partial('sidebar', ['sidebar' => $data['sidebar']]) ?>
<main id="main" class="page page-list" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'record') : ?>
<main id="main" class="page page-record" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'record-narrow') : ?>
<main id="main" class="page page-record page-record-narrow" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'settings') : ?>
<main id="main" class="page page-settings" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'io') : ?>
<main id="main" class="page page-io" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'picker') : ?>
<main id="main" class="page page-picker" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'collection') : ?>
<main id="main" class="page page-collection" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'trash') : ?>
<main id="main" class="page page-trash" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'form') : ?>
<main id="main" class="page page-form" tabindex="-1">
<?php elseif (($data['layout'] ?? null) === 'form-narrow') : ?>
<main id="main" class="page page-form page-form-narrow" tabindex="-1">
<?php else : ?>
<main id="main" class="page" tabindex="-1">
<?php endif; ?>
<?php else : ?>
<?= $this->partial('lockup') ?>
<?php if (($data['wide'] ?? false) === true) : ?>
<main id="main" class="page" tabindex="-1">
<?php else : ?>
<main id="main" class="page page-plain" tabindex="-1">
<?php endif; ?>
<?php endif; ?>
<?php if (is_array($data['breadcrumb'] ?? null)) : ?>
<?= $this->partial('breadcrumb', ['items' => $data['breadcrumb']]) ?>
<?php endif; ?>
<?php if (is_array($data['shell'] ?? null) && is_array($data['shell']['flash']) && is_string($data['shell']['flash']['params']['undo'] ?? null)) : ?>
<?= $this->partial('notice', ['key' => $data['shell']['flash']['key'], 'params' => $data['shell']['flash']['params'], 'tone' => 'info', 'undo' => $data['shell']['flash']['params']['undo'], 'undoTitle' => $data['shell']['flash']['params']['title'] ?? '']) ?>
<?php elseif (is_array($data['shell'] ?? null) && is_array($data['shell']['flash'])) : ?>
<?= $this->partial('notice', ['key' => $data['shell']['flash']['key'], 'params' => $data['shell']['flash']['params'], 'tone' => 'info']) ?>
<?php endif; ?>
<?php if (is_array($data['problems'] ?? null) && count($data['problems']) > 0) : ?>
<?= $this->partial('error-summary', ['problems' => $data['problems']]) ?>
<?php endif; ?>
<?= $this->slot('content') ?>
</main>
<?php if (is_array($data['shell'] ?? null) && ($data['layout'] ?? null) === 'list' && is_array($data['sidebar'] ?? null)) : ?>
</div>
<?php endif; ?>
<?php if (is_array($data['shell'] ?? null)) : ?>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl'], 'centered' => false, 'about' => true]) ?>
<?php else : ?>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl'], 'centered' => true]) ?>
<?php endif; ?>
</div>
</body>
</html>
