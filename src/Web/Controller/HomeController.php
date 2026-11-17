<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\Install\InstallState;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * `/` (M1 spec part 2 section 3.1): no page of its own. Sends the visitor
 * to setup before installation, to the project list when signed in, and
 * to sign-in otherwise.
 */
final class HomeController
{
    public function __construct(private readonly Context $context)
    {
    }

    public function show(Request $request): Response
    {
        if ($this->context->services()->installState() !== InstallState::Installed) {
            return $this->context->redirect('/setup');
        }

        return $this->context->redirect($this->context->signedIn() === null ? '/sign-in' : '/projects');
    }
}
