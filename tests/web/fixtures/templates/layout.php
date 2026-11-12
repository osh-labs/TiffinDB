<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: a minimal layout for the request-pipeline tests. The real
 * layout is templates/layout.php.
 */

declare(strict_types=1);

?>
<!DOCTYPE html>
<html lang="en"<?= $this->rootClassAttribute() ?>>
<head>
<meta charset="utf-8" />
<?php if (($data['title'] ?? null) === null): ?>
<title><?= $this->t('app.name') ?></title>
<?php else: ?>
<title><?= $this->t('shell.title', ['title' => $data['title']]) ?></title>
<?php endif; ?>
<link rel="stylesheet" href="<?= $this->asset('css/app.css') ?>" />
</head>
<body>
<header><a href="<?= $this->url('/') ?>"><?= $this->t('app.name') ?></a></header>
<main id="main"><?= $this->slot('content') ?></main>
<?= $this->partial('footer', ['sourceUrl' => $data['sourceUrl']]) ?>
</body>
</html>
