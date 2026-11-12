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

    /** Opens an existing file. Throws Unavailable('missing') when it does not exist. */
    public static function open(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database
    {
        if (!is_file($path)) {
            throw new Unavailable(Unavailable::MISSING);
        }

        return self::connect($path, $busyTimeoutMs, false);
    }

    /** Creates a new file; throws \RuntimeException when the path already exists. */
    public static function create(string $path, int $busyTimeoutMs = self::BUSY_TIMEOUT_MS): Database
    {
        if ($path === '' || $path === self::MEMORY) {
            throw new \InvalidArgumentException('Sqlite::create needs a file path.');
        }
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('Cannot create a database: the path already exists.');
        }

        return self::connect($path, $busyTimeoutMs, true);
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
