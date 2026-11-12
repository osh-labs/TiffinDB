<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The system database, `<data>/system.sqlite` (ADR 0011 decision 2, M1
 * spec sections 5.2 and 5.3).
 *
 * The HMAC key (meta `hmac_key`, 64 hexadecimal characters) is used as its
 * 32 raw bytes. It is read once per instance and never returned, logged,
 * or put in an exception message.
 */
final class SystemDatabase
{
    public const META_SCHEMA_VERSION = 'schema_version';
    public const META_INSTALLATION_KEY = 'installation_key';
    public const META_HMAC_KEY = 'hmac_key';
    public const META_CREATED_AT = 'created_at';
    public const META_INSTALLED_AT = 'installed_at';
    public const META_INSTALLED_WITH = 'installed_with';

    private ?string $hmacKey = null;

    private function __construct(private readonly Database $db)
    {
    }

    /** Creates system.sqlite in the data directory, migrates, writes installation_key, hmac_key, created_at. */
    public static function create(DataDirectory $data, Clock $clock, Random $random): self
    {
        $db = Sqlite::create($data->systemDatabase());
        try {
            SystemSchema::migrate($db, null, $clock);
            $installationKey = (new Keys($random))->generate();
            $hmacKey = bin2hex($random->bytes(32));
            $createdAt = Timestamp::format($clock->now());
            $db->write(static function (Database $db) use ($installationKey, $hmacKey, $createdAt): void {
                $insert = 'INSERT INTO meta (key, value) VALUES (?, ?)';
                $db->run($insert, [self::META_INSTALLATION_KEY, $installationKey]);
                $db->run($insert, [self::META_HMAC_KEY, $hmacKey]);
                $db->run($insert, [self::META_CREATED_AT, $createdAt]);
            });
        } catch (\Throwable $error) {
            // Sqlite::create refused an existing path, so the file was made by this call.
            $db->close();
            foreach (['', '-wal', '-shm'] as $suffix) {
                Fs::remove($data->systemDatabase() . $suffix);
            }

            throw $error;
        }

        return new self($db);
    }

    /**
     * Opens system.sqlite and migrates it forward when schema_version is
     * lower than SystemSchema::VERSION. Throws Unavailable('missing') when
     * there is no file, Unavailable('newer') when its version is higher,
     * and Unavailable('not-project') when the file is not a TiffinDB system
     * database (no readable schema_version).
     */
    public static function open(DataDirectory $data): self
    {
        $db = Sqlite::open($data->systemDatabase());
        try {
            try {
                $version = SystemSchema::currentVersion($db);
            } catch (\PDOException) {
                throw new Unavailable(Unavailable::NOT_PROJECT);
            }
            if ($version < 1) {
                throw new Unavailable(Unavailable::NOT_PROJECT);
            }
            SystemSchema::migrate($db);
        } catch (\Throwable $error) {
            $db->close();

            throw $error;
        }

        return new self($db);
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function meta(string $key): ?string
    {
        $value = $this->db->value('SELECT value FROM meta WHERE key = ?', [$key]);

        return is_string($value) ? $value : null;
    }

    public function setMeta(string $key, string $value): void
    {
        if ($key === self::META_HMAC_KEY || $key === self::META_SCHEMA_VERSION) {
            throw new \InvalidArgumentException('This meta key is managed by SystemDatabase and SystemSchema.');
        }
        $this->db->run(
            'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
            [$key, $value],
        );
    }

    /** True once the first Admin exists (meta installed_at present). */
    public function isInstalled(): bool
    {
        return $this->meta(self::META_INSTALLED_AT) !== null;
    }

    /** Lower-case hex HMAC-SHA256, keyed with hmac_key, over "$purpose\0$value". */
    public function hmac(string $purpose, string $value): string
    {
        if ($purpose === '' || str_contains($purpose, "\0")) {
            throw new \InvalidArgumentException('An HMAC purpose must be non-empty and hold no NUL byte.');
        }

        return hash_hmac('sha256', $purpose . "\0" . $value, $this->hmacKey());
    }

    /**
     * Throws \LogicException unless a write() transaction is open. Called
     * by repository methods that change rows (P51): the business operation
     * owns the transaction.
     */
    public function requireTransaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('This repository method changes rows and must run inside Database::write().');
        }
    }

    private function hmacKey(): string
    {
        if ($this->hmacKey === null) {
            $hex = $this->meta(self::META_HMAC_KEY);
            if ($hex === null || preg_match('/^[0-9a-f]{64}\z/', $hex) !== 1) {
                throw new \LogicException('The system database has no valid HMAC key.');
            }
            $this->hmacKey = (string) hex2bin($hex);
        }

        return $this->hmacKey;
    }
}
