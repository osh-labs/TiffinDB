<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: controllers injected through the kernel's route-table
 * parameter. Loaded with require_once by the tests that use them.
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web\Fixtures;

use RuntimeException;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\View;

final class FixtureController
{
    public const SECRET_MESSAGE = 'Controller failure reading /fixture/secret/config.php';

    public function __construct(private readonly View $view)
    {
    }

    public function throws(Request $request): Response
    {
        throw new RuntimeException(self::SECRET_MESSAGE);
    }

    public function partialFailure(Request $request): Response
    {
        return $this->view->page('partial-failure', []);
    }

    public function warning(Request $request): Response
    {
        return $this->view->page('warning', []);
    }

    public function notAResponse(Request $request): string
    {
        return 'not a response';
    }

    public function badTemplateName(Request $request): Response
    {
        return $this->view->page('../layout', []);
    }

    /** @param array<string, string> $params */
    public function params(Request $request, array $params): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'id=' . ($params['id'] ?? ''));
    }

    public function setsHeaders(Request $request): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'content-security-policy' => "default-src *",
            'X-Frame-Options' => 'ALLOWALL',
        ], 'ok');
    }
}
