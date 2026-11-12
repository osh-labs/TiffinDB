<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The M0 start page strings (M0 spec section 9.4), moved here unchanged so
 * that the M0 kernel still serves `/`. Deleted together with
 * templates/pages/home.php when `/` becomes a redirect (M1-WP7).
 */

declare(strict_types=1);

return [
    'home.title' => 'TiffinDB',
    'home.lead' => 'This is an early build. There is nothing to set up or sign in to yet.',
    'home.preview.label' => 'Preview a theme',
    'home.preview.light' => 'Light',
    'home.preview.dark' => 'Dark',
    'home.preview.hc-light' => 'High contrast, light',
    'home.preview.hc-dark' => 'High contrast, dark',
];
