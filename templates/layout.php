<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The application shell (M0 spec section 9.1): document head, skip link,
 * top bar, main area holding the page (the `content` slot), and footer.
 *
 * Data: `title` (plain-text page title, or null for the start page) and
 * `sourceUrl` (the configured "Source code" link target, set by the view).
 * Output is HTML5 in XML-well-formed syntax (ADR 0008 decision 4).
 */

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en"<?= $this->rootClassAttribute() ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php if (($data['title'] ?? null) === null) : ?>
<title><?= $this->t('app.name') ?></title>
<?php else : ?>
<title><?= $this->t('shell.title', ['title' => $data['title']]) ?></title>
<?php endif; ?>
<link rel="stylesheet" href="<?= $this->asset('css/fonts.css') ?>" />
<link rel="stylesheet" href="<?= $this->asset('css/tokens.css') ?>" />
<link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>" />
</head>
<body>
<a class="skip-link" href="#main"><?= $this->t('shell.skip') ?></a>
<div class="app">
<?= $this->partial('top-bar') ?>
<main id="main" class="page" tabindex="-1">
<?= $this->slot('content') ?>
</main>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl']]) ?>
</div>
</body>
</html>
