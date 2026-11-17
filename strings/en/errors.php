<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Error page strings (M0 spec section 9.4; M1 spec part 2 section 5.2).
 * The kernel builds the keys at run time: error.<status>.title and .body,
 * error.csrf.*, error.busy.*, error.unavailable.<reason>.*.
 */

declare(strict_types=1);

return [
    'error.back' => 'Go to the start page',
    'error.400.title' => 'That address is not valid',
    'error.400.body' => 'TiffinDB could not read the address of this page.',
    'error.403.title' => 'You cannot do that here',
    'error.403.body' => 'Your account does not allow this. Ask an Admin if you need it.',
    'error.404.title' => 'Page not found',
    'error.404.body' => 'There is no page at this address.',
    'error.405.title' => 'That action is not available here',
    'error.405.body' => 'This address does not accept that kind of request.',
    'error.500.title' => 'Something went wrong',
    'error.500.body' => 'TiffinDB could not show this page. Nothing was changed.',
    'error.csrf.title' => 'This form has expired',
    'error.csrf.body' => 'Go back, reload the page, and try again. Nothing was saved.',
    'error.busy.title' => 'Please try again in a moment',
    'error.busy.body' => 'Someone else is saving a change to this project right now. Nothing was lost. Wait a moment, then try again.',
    'error.unavailable.missing.title' => 'This project\'s file cannot be found',
    'error.unavailable.missing.body' => 'The project folder or its database file is missing from the data folder. The person who looks after this TiffinDB can restore it from a backup.',
    'error.unavailable.not-project.title' => 'This project\'s file cannot be read',
    'error.unavailable.not-project.body' => 'The file in the project folder is not a TiffinDB project, or it is damaged. The person who looks after this TiffinDB can restore it from a backup or a snapshot.',
    'error.unavailable.newer.title' => 'This project needs a newer TiffinDB',
    'error.unavailable.newer.body' => 'It was saved by a newer version of TiffinDB. Update TiffinDB to open it.',
    'error.unavailable.mismatch.title' => 'This project\'s file does not match',
    'error.unavailable.mismatch.body' => 'The file in the project folder belongs to a different project. The person who looks after this TiffinDB should check the data folder.',
];
