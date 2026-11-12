<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Unavailable;

/**
 * Opens SQLite connections with the settings of M1 spec section 4.1, in
 * this order: PDO attributes, busy timeout, WAL journal (files only),
 * synchronous NORMAL, foreign keys on.
 */
final class Sqlite
{
    /** The floor for ALTER TABLE ... DROP COLUMN (P38, ADR 0010). */
    public const MIN_VERSION = '3.35.5';
    public const BUSY_TIMEOUT_MS = 5000;
    public const MEMORY = ':memory:';

    private function __construct()
    {
    }

    /**
     * Opens an existing file. Throws Unavailable('missing') when it does not
     * exist, and \InvalidArgumentException for a path that is not absolute,
     * is a `file:` URI, holds a NUL byte, or is MEMORY (use memory()).
     */
    public static function open(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database
    {
        self::checkPath($path);
        if (!is_file($path)) {
            throw new Unavailable(Unavailable::MISSING);
        }

        return self::connect($path, $busyTimeoutMs, false);
    }

    /**
     * Creates a new file with mode 0640 (set before WAL is switched on, so
     * the -wal and -shm files that SQLite creates take the same mode).
     * Throws \RuntimeException when the path already exists, and
     * \InvalidArgumentException for the paths open() refuses.
     */
    public static function create(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database
    {
        self::checkPath($path);
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Cannot create a database: the path already exists.');
        }
        try {
            return self::connect($path, $busyTimeoutMs, true);
        } catch (\Throwable $error) {
            // Nothing existed at the path before, so whatever is there now was made by this call.
            foreach (['', '-wal', '-shm'] as $suffix) {
                Fs::remove($path . $suffix);
            }

            throw $error;
        }
    }

    /** A private in-memory database, for tests and the requirement check. */
    public static function memory(): Database
    {
        return self::connect(self::MEMORY, self::BUSY_TIMEOUT_MS, true);
    }

    /** The SQLite library version, or null when pdo_sqlite is missing. */
    public static function libraryVersion(): ?string
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }
        $version = (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();

        return is_string($version) ? $version : null;
    }

    /** Whether $version is at least MIN_VERSION. */
    public static function isSupported(string $version): bool
    {
        return version_compare($version, self::MIN_VERSION, '>=');
    }

    private static function checkPath(string $path): void
    {
        if ($path === self::MEMORY) {
            throw new \InvalidArgumentException('Use Sqlite::memory() for a memory database.');
        }
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('A database path must not contain a NUL byte.');
        }
        if (preg_match('/^file:/i', $path) === 1) {
            throw new \InvalidArgumentException('A database path must be a plain path, not a file: URI.');
        }
        if (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:[/\\\\]#', $path) !== 1) {
            throw new \InvalidArgumentException('A database path must be absolute.');
        }
    }

    private static function connect(string $path, int $busyTimeoutMs, bool $mayCreate): Database
    {
        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('The busy timeout must be 0 or more.');
        }
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (!$mayCreate) {
            // Open read-write without the create flag, so a file removed in the meantime is not recreated empty.
            $options[\PDO::SQLITE_ATTR_OPEN_FLAGS] = \PDO::SQLITE_OPEN_READWRITE;
        }
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, $options);
            if ($mayCreate && $path !== self::MEMORY) {
                self::restrictMode($path);
            }
            // PRAGMA takes no bound parameters; the value is a checked integer.
            $pdo->exec('PRAGMA busy_timeout = ' . (string) $busyTimeoutMs);
            if ($path === self::MEMORY) {
                $journal = self::pragma($pdo, 'PRAGMA journal_mode');
            } else {
                $journal = self::pragma($pdo, 'PRAGMA journal_mode = WAL');
            }
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } catch (\PDOException $error) {
            $code = $error->errorInfo[1] ?? null;
            if ($code === 5 || $code === 6) {
                throw new Busy('SQLite was busy or locked while opening a connection.', 0, $error);
            }

            throw $error;
        }

        return new Database($pdo, $path, strtolower($journal));
    }

    private static function restrictMode(string $path): void
    {
        $changed = ErrorTrap::run(static fn (): bool => chmod($path, Fs::FILE_MODE), 'Cannot set the database file mode.');
        if (!$changed) {
            throw new \RuntimeException('Cannot set the database file mode.');
        }
    }

    private static function pragma(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $value = $statement === false ? false : $statement->fetchColumn();
        if ($statement !== false) {
            $statement->closeCursor();
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
