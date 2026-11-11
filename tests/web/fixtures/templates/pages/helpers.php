<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: prints each scope helper inside a marked element, and
 * reports which variables are in scope.
 */

declare(strict_types=1);

?>
<h1><?= $this->t('test.greeting', ['name' => $data['name']]) ?></h1>
<p id="e"><?= $this->e($data['value']) ?></p>
<p id="url"><?= $this->url('/projects', ['q' => $data['name']]) ?></p>
<p id="asset"><?= $this->asset('css/app.css') ?></p>
<p id="partial"><?= $this->partial('item', ['label' => $data['name']]) ?></p>
<p id="vars"><?= $this->e(implode(',', array_keys(get_defined_vars()))) ?></p>
