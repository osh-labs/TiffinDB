<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The one-time Admin recovery code file (M7 spec section 9.1, ADR 0022
 * decision 5): `<data>/recovery-code.txt`, readable only by someone with
 * access to the host's files. The code has the form and strength of the
 * setup code (SetupCode::generate) and is valid for VALID_SECONDS from the
 * time written in the file.
 *
 * The code is read back only by current(); nothing here returns it to a
 * caller that shows it, and no log line or exception message holds it or
 * the path. A symbolic link at the path is never followed: current() is
 * null and start() refuses. Only the caller's system write serialises
 * start() and remove() between requests.
 */
final class RecoveryCode
{
    public const FILE = 'recovery-code.txt';
    public const VALID_SECONDS = 1800;
    public const MAX_FILE_BYTES = 1024;

    private const FIRST_LINE = 'TiffinDB Admin recovery code';
    private const LAST_LINE = 'This code works once, for 30 minutes. TiffinDB deletes this file when it is used.';

    private function __construct()
    {
    }

    /**
     * Writes <data>/recovery-code.txt with a new code unless a valid one
     * exists; true when written. An expired, unreadable, or malformed file
     * is removed first. A symbolic link at the path, or a file that cannot
     * be removed or written, is refused: logged without the path, false.
     */
    public static function start(string $dataPath, Random $random, \DateTimeImmutable $now): bool
    {
        $path = self::path($dataPath);
        if (is_link($path)) {
            error_log('TiffinDB: the recovery code path is a symbolic link, so no code was written.');

            return false;
        }
        if (self::current($dataPath, $now) !== null) {
            return false;
        }
        try {
            if (Fs::exists($path)) {
                Fs::remove($path);
            }
            Fs::writeNew($path, self::text(SetupCode::generate($random), $now));
        } catch (\RuntimeException $error) {
            error_log('TiffinDB: the recovery code file could not be written (' . $error::class . ').');

            return false;
        }

        return true;
    }

    /**
     * The stored code when the file exists, is a regular file (not a
     * link) of at most MAX_FILE_BYTES, is exactly the text start() writes,
     * and has not expired; else null.
     */
    public static function current(string $dataPath, \DateTimeImmutable $now): ?string
    {
        $text = self::read(self::path($dataPath));
        if ($text === null) {
            return null;
        }
        $pattern = '/^' . preg_quote(self::FIRST_LINE, '/') . '\n([A-Z0-9-]{19})\nCreated (\S+)\n'
            . preg_quote(self::LAST_LINE, '/') . '\n\z/';
        if (preg_match($pattern, $text, $m) !== 1 || preg_match(SetupCode::PATTERN, $m[1]) !== 1) {
            return null;
        }
        try {
            $created = Timestamp::parse($m[2])->getTimestamp();
        } catch (\InvalidArgumentException) {
            return null;
        }
        $at = $now->getTimestamp();
        if ($created > $at || $at - $created >= self::VALID_SECONDS) {
            return null;
        }

        return $m[1];
    }

    /** Removes the file (or a link at its path); no error when absent, \RuntimeException when it cannot be removed. */
    public static function remove(string $dataPath): void
    {
        Fs::remove(self::path($dataPath));
    }

    /** The exact text of the file (M7 spec section 9.1). */
    public static function text(#[\SensitiveParameter] string $code, \DateTimeImmutable $created): string
    {
        return self::FIRST_LINE . "\n" . $code . "\nCreated " . Timestamp::format($created) . "\n" . self::LAST_LINE . "\n";
    }

    private static function path(string $dataPath): string
    {
        return rtrim($dataPath, '/') . '/' . self::FILE;
    }

    /**
     * The file's bytes when it is a regular file of at most MAX_FILE_BYTES
     * in valid UTF-8, opened without following a link: the file opened
     * must be the one checked with lstat (same device and inode), else null.
     */
    private static function read(string $path): ?string
    {
        if (!Fs::isFile($path)) {
            return null;
        }
        try {
            $before = ErrorTrap::run(static fn (): array|false => lstat($path), 'Cannot read the file status.');
            if ($before === false || $before['size'] > self::MAX_FILE_BYTES) {
                return null;
            }
            $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'rb'), 'Cannot open the file.');
            if (!is_resource($handle)) {
                return null;
            }
            try {
                $opened = fstat($handle);
                if (
                    $opened === false || ($opened['mode'] & 0170000) !== 0100000
                    || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
                ) {
                    return null;
                }
                $text = ErrorTrap::run(static fn (): string|false => fread($handle, self::MAX_FILE_BYTES + 1), 'Cannot read the file.');
            } finally {
                fclose($handle);
            }
        } catch (\RuntimeException) {
            return null;
        }
        if (!is_string($text) || strlen($text) > self::MAX_FILE_BYTES || preg_match('//u', $text) !== 1) {
            return null;
        }

        return $text;
    }
}
