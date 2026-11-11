<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * User interface strings, English (ADR 0008 decision 5; M0 spec section
 * 9.4). A flat map of 'dotted.key' => 'text'. Placeholders are {name}; the
 * view escapes the text and every substituted value.
 */

declare(strict_types=1);

return [
    'app.name' => 'TiffinDB',

    'shell.skip' => 'Skip to main content',
    'shell.brand.label' => 'TiffinDB, start page',
    'shell.title' => '{title} - TiffinDB',
    'shell.footer.licence' => 'TiffinDB, free software under AGPL-3.0-or-later',
    'shell.footer.source' => 'Source code',

    'home.title' => 'TiffinDB',
    'home.lead' => 'This is an early build. There is nothing to set up or sign in to yet.',
    'home.preview.label' => 'Preview a theme',
    'home.preview.light' => 'Light',
    'home.preview.dark' => 'Dark',
    'home.preview.hc-light' => 'High contrast, light',
    'home.preview.hc-dark' => 'High contrast, dark',

    'error.back' => 'Go to the start page',
    'error.400.title' => 'That address is not valid',
    'error.400.body' => 'TiffinDB could not read the address of this page.',
    'error.404.title' => 'Page not found',
    'error.404.body' => 'There is no page at this address.',
    'error.405.title' => 'That action is not available here',
    'error.405.body' => 'This page can be viewed but not changed.',
    'error.500.title' => 'Something went wrong',
    'error.500.body' => 'TiffinDB could not show this page. Nothing was changed.',
];
