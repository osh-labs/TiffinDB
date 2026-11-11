<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The route table (ADR 0008 decision 2): a list of
 * [method, pattern, [ControllerClass, 'method']]. First match wins.
 */

declare(strict_types=1);

use TiffinDB\Web\Controller\HomeController;

return [
    ['GET', '/', [HomeController::class, 'show']],
];
