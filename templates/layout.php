<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The application shell (M0 spec section 9.1; M1 spec part 2 section 4.1):
 * document head, skip link, then one of two shells.
 *
 * - Signed-in shell (`shell` is an array from Shell::toArray()): top bar,
 *   `main.page`, footer.
 * - Plain shell (`shell` null): lockup header, `main.page.page-plain` (or
 *   `main.page` when `wide` is true), centred footer.
 *
 * In both, `main` starts with what every page shares, in this order: the
 * breadcrumb (`breadcrumb`), the flash notice (signed in only), and the
 * error summary (`problems`); then the page (the `content` slot). Pages do
 * not print these themselves.
 *
 * Data (see View for the full contract): `title`, `documentTitle`,
 * `hasError`, `sourceUrl`, `shell`, `wide`, `script`, `breadcrumb`,
 * `problems`. Output is HTML5 in XML-well-formed syntax (ADR 0008
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
<?php if (($data['script'] ?? null) === 'show-password') : ?>
<script src="<?= $this->asset('js/show-password.js') ?>" defer="defer"></script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main"><?= $this->t('shell.skip') ?></a>
<div class="app">
<?php if (is_array($data['shell'] ?? null)) : ?>
<?= $this->partial('top-bar', ['shell' => $data['shell']]) ?>
<main id="main" class="page" tabindex="-1">
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
<?php if (is_array($data['shell'] ?? null) && is_array($data['shell']['flash'])) : ?>
<?= $this->partial('notice', ['key' => $data['shell']['flash']['key'], 'params' => $data['shell']['flash']['params'], 'tone' => 'info']) ?>
<?php endif; ?>
<?php if (is_array($data['problems'] ?? null) && count($data['problems']) > 0) : ?>
<?= $this->partial('error-summary', ['problems' => $data['problems']]) ?>
<?php endif; ?>
<?= $this->slot('content') ?>
</main>
<?php if (is_array($data['shell'] ?? null)) : ?>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl'], 'centered' => false]) ?>
<?php else : ?>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl'], 'centered' => true]) ?>
<?php endif; ?>
</div>
</body>
</html>
