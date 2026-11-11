<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Front controller: the only PHP entry point (ADR 0008 decision 2; M0 spec
 * section 6.1). Builds a Request, hands it to the kernel, sends the reply.
 */

declare(strict_types=1);

// No PHP message may ever put a file path into a response.
ini_set('display_errors', '0');

$server = $_SERVER;

// PHP built-in development server only: let it send existing static files
// under public/ itself. Never a .php file and never a dot-file.
if (PHP_SAPI === 'cli-server') {
    $requestPath = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
    $requestPath = rawurldecode(explode('?', $requestPath, 2)[0]);
    $publicDir = realpath(__DIR__);
    if (
        $publicDir !== false
        && !str_contains($requestPath, "\0")
        && preg_match('#(^|/)\.#', $requestPath) !== 1
    ) {
        $candidate = realpath($publicDir . '/' . ltrim($requestPath, '/'));
        if (
            $candidate !== false
            && str_starts_with($candidate, $publicDir . DIRECTORY_SEPARATOR)
            && is_file($candidate)
            && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php'
        ) {
            return false;
        }
    }
    // The built-in server reports the request path as SCRIPT_NAME when it
    // contains a dot or names a directory; that is not a base path.
    $server['SCRIPT_NAME'] = '/index.php';
}

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../src/Web/Response.php';
    require __DIR__ . '/../src/Web/SecurityHeaders.php';
    (new TiffinDB\Web\SecurityHeaders())->apply(new TiffinDB\Web\Response(
        500,
        ['Content-Type' => 'text/plain; charset=utf-8'],
        "TiffinDB cannot start because its dependencies are not installed.\n",
    ))->send();
    return;
}

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/app.php';
$kernel = new TiffinDB\Web\Kernel(is_array($config) ? $config : [], dirname(__DIR__));
$request = TiffinDB\Web\Request::fromServer($server, $_GET);
$kernel->handle($request)->send($request->method === 'HEAD');
