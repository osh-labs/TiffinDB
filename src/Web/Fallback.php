<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use Throwable;

/**
 * The last-resort reply: a fixed plain-text 500 with the full security
 * header set, for failures the kernel cannot turn into an error page
 * (missing dependencies, a broken configuration file, fatal errors such as
 * an exhausted memory or time limit).
 *
 * public/index.php loads this file, Response.php, and SecurityHeaders.php
 * directly, before the autoloader, so it must depend on those two only.
 */
final class Fallback
{
    public const BODY = "Something went wrong. TiffinDB could not show this page.\n";
    public const DEPENDENCIES_BODY = "TiffinDB cannot start because its dependencies are not installed.\n";

    /** Error types that end the script and cannot be caught. */
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    private function __construct()
    {
    }

    public static function response(string $body = self::BODY): Response
    {
        return (new SecurityHeaders())->apply(
            new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $body),
        );
    }

    /**
     * Discards any buffered partial output and sends the fallback, unless
     * headers have already gone out (then nothing more can be done safely).
     */
    public static function send(string $body = self::BODY): void
    {
        if (headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        self::response($body)->send();
    }

    /** Shutdown function: sends the fallback after an uncatchable fatal error. */
    public static function onShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], self::FATAL_TYPES, true)) {
            self::send();
        }
    }

    /**
     * One line to the server's error log: class, message, file, and line.
     * No request data; never part of the response.
     */
    public static function log(Throwable $e): void
    {
        $line = sprintf('TiffinDB: %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        error_log((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line));
    }
}
