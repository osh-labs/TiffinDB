<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * Runs a file-system call under a local error handler that turns any PHP
 * warning or notice into a \RuntimeException, restoring the previous
 * handler afterwards (M1 spec section 4.3). Used instead of the `@`
 * operator. The exception message never includes the warning text, which
 * can hold paths outside the data directory; the warning is kept only as
 * the message of the previous exception, for logs.
 */
final class ErrorTrap
{
    private function __construct()
    {
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public static function run(callable $call, string $what): mixed
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }
        if ($warning !== null) {
            throw new \RuntimeException($what, 0, new \ErrorException($warning));
        }

        return $result;
    }
}
