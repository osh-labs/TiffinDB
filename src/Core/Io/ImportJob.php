<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Sqlite;

/**
 * One import job: its folder and its `job.sqlite` (M3 spec section 5.2),
 * TiffinDB's own scratch file (not a documented format). The job file
 * holds the staged rows as text, the prepared rows as storage values, the
 * per-cell issues, and the meta values listed in section 5.2.
 *
 * Every operation on a job first takes the job lock (lock(), an exclusive
 * non-blocking flock on `job.lock`) and reads the state only after holding
 * it; another request holding the lock gives Busy. The lock is released by
 * unlock() or close(), and by the operating system if the request dies.
 */
final class ImportJob
{
    public const FILE = 'job.sqlite';
    public const UPLOAD = 'upload.bin';

    private const SCHEMA = [
        'CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL) WITHOUT ROWID',
        'CREATE TABLE rows (n INTEGER PRIMARY KEY, cells TEXT NOT NULL)',
        'CREATE TABLE prepared (n INTEGER PRIMARY KEY, record TEXT, skip TEXT)',
        'CREATE TABLE issues (n INTEGER NOT NULL, col INTEGER NOT NULL, kind TEXT NOT NULL, PRIMARY KEY (n, col)) WITHOUT ROWID',
    ];

    /** Meta keys a job may hold (section 5.2, plus the read counters and the failure reason). */
    public const META_KEYS = [
        'project', 'user', 'plugin', 'file_name', 'file_size', 'file_sha256', 'extension', 'settings', 'inspection',
        'table_index', 'state', 'failure', 'read_rows', 'read_done', 'skipped', 'replaced', 'stats', 'mapping', 'schema_hash',
        'prepared_rows', 'created_at', 'report',
    ];

    /** Issue kinds written while preparing; cleared when the mapping is saved again. */
    public const PREPARE_ISSUES = ['unreadable', 'not-a-choice'];

    /** @var resource|null */
    private $lock = null;

    private function __construct(
        private readonly Database $db,
        private readonly string $folder,
        private readonly string $key,
    ) {
    }

    /**
     * Creates `job.sqlite` in a job folder made by StagingArea and stores
     * the given meta values; the job starts in Reading.
     *
     * @param array<string, string> $meta
     */
    public static function create(string $folder, array $meta): self
    {
        $key = self::keyOf($folder);
        $db = Sqlite::create($folder . '/' . self::FILE);
        try {
            $db->write(static function (Database $db) use ($meta): void {
                foreach (self::SCHEMA as $statement) {
                    $db->run($statement);
                }
                $defaults = ['state' => JobState::Reading->value, 'read_rows' => '0', 'read_done' => '0', 'prepared_rows' => '0'];
                foreach ([...$defaults, ...$meta] as $name => $value) {
                    self::checkMeta((string) $name, $value);
                    $db->run('INSERT INTO meta (key, value) VALUES (?, ?)', [(string) $name, $value]);
                }
            });
        } catch (\Throwable $error) {
            $db->close();

            throw $error;
        }

        return new self($db, $folder, $key);
    }

    /** Opens the job file of an existing job folder; NotFound when it has none. */
    public static function open(string $folder): self
    {
        $key = self::keyOf($folder);
        if (!Fs::isFile($folder . '/' . self::FILE)) {
            throw new NotFound('No such import job.');
        }

        return new self(Sqlite::open($folder . '/' . self::FILE), $folder, $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function folder(): string
    {
        return $this->folder;
    }

    public function db(): Database
    {
        return $this->db;
    }

    /**
     * Takes the job lock (exclusive, non-blocking). Busy when another
     * request holds it. A second call while held is a no-op.
     */
    public function lock(): void
    {
        if ($this->lock !== null) {
            return;
        }
        $path = $this->folder . '/' . StagingArea::LOCK;
        if (!Fs::isFile($path)) {
            throw new NotFound('The import job has no lock file.');
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'r'), 'Cannot open the job lock.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the job lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new Busy('Another request is working on this import job.');
        }
        $this->lock = $handle;
    }

    public function isLocked(): bool
    {
        return $this->lock !== null;
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /** Closes the job file and releases the lock. */
    public function close(): void
    {
        $this->db->close();
        $this->unlock();
    }

    /** NotFound unless the job belongs to this project and this person (P134). */
    public function checkOwner(string $projectKey, int $userId): void
    {
        if ($this->meta('project') !== $projectKey || $this->meta('user') !== (string) $userId) {
            throw new NotFound('No such import job.');
        }
    }

    public function meta(string $name): ?string
    {
        self::checkName($name);
        $value = $this->db->value('SELECT value FROM meta WHERE key = ?', [$name]);

        return is_string($value) ? $value : null;
    }

    /** Sets a meta value; the caller holds the lock (and may be inside a job-file transaction). */
    public function setMeta(string $name, string $value): void
    {
        self::checkMeta($name, $value);
        $this->db->run('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$name, $value]);
    }

    public function removeMeta(string $name): void
    {
        self::checkName($name);
        $this->db->run('DELETE FROM meta WHERE key = ?', [$name]);
    }

    /** A JSON meta value decoded to an array, or null when absent. */
    public function metaJson(string $name): ?array
    {
        $text = $this->meta($name);
        if ($text === null) {
            return null;
        }
        $value = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \UnexpectedValueException('A job meta value is not a JSON array or object.');
        }

        return $value;
    }

    /** @param array<mixed> $value */
    public function setMetaJson(string $name, array $value): void
    {
        $this->setMeta($name, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function metaInt(string $name): int
    {
        $value = $this->meta($name);

        return $value !== null && preg_match('/^-?\d{1,18}\z/', $value) === 1 ? (int) $value : 0;
    }

    public function state(): JobState
    {
        $state = JobState::tryFrom((string) $this->meta('state'));
        if ($state === null) {
            throw new \UnexpectedValueException('The job file holds no valid state.');
        }

        return $state;
    }

    /** The failure reason code of a Failed job, else null. */
    public function failure(): ?string
    {
        return $this->state() === JobState::Failed ? $this->meta('failure') : null;
    }

    /** Moves to $to; \LogicException for a transition section 5.2 does not allow. */
    public function moveTo(JobState $to): void
    {
        if (!$this->state()->canMoveTo($to)) {
            throw new \LogicException('This import job cannot move to that state.');
        }
        $this->setMeta('state', $to->value);
    }

    /** Moves to Failed with a reason code (lower case, digits, hyphens). */
    public function fail(string $reason): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,39}\z/', $reason) !== 1) {
            throw new \InvalidArgumentException('A failure reason is a short code.');
        }
        $this->db->write(function () use ($reason): void {
            $this->moveTo(JobState::Failed);
            $this->setMeta('failure', $reason);
        });
    }

    /**
     * Back to Reading with rows, prepared rows, issues, statistics, mapping,
     * and counters emptied (the reading settings changed).
     */
    public function resetReading(): void
    {
        $this->db->write(function (Database $db): void {
            $this->moveTo(JobState::Reading);
            $db->run('DELETE FROM rows');
            $db->run('DELETE FROM prepared');
            $db->run('DELETE FROM issues');
            foreach (['stats', 'mapping', 'schema_hash', 'skipped', 'replaced', 'report'] as $name) {
                $this->removeMeta($name);
            }
            $this->setMeta('read_rows', '0');
            $this->setMeta('read_done', '0');
            $this->setMeta('prepared_rows', '0');
        });
    }

    /**
     * Stores an accepted mapping and its target hash and moves to
     * Preparing, emptying earlier prepared rows and their issues.
     */
    public function acceptMapping(Mapping $mapping, string $schemaHash): void
    {
        if (preg_match('/^[0-9a-f]{64}\z/', $schemaHash) !== 1) {
            throw new \InvalidArgumentException('The schema hash is a SHA-256 hex digest.');
        }
        $this->db->write(function (Database $db) use ($mapping, $schemaHash): void {
            $this->moveTo(JobState::Preparing);
            $db->run('DELETE FROM prepared');
            $db->run('DELETE FROM issues WHERE kind IN (' . Database::placeholders(count(self::PREPARE_ISSUES)) . ')', self::PREPARE_ISSUES);
            $this->setMetaJson('mapping', $mapping->toArray());
            $this->setMeta('schema_hash', $schemaHash);
            $this->setMeta('prepared_rows', '0');
        });
    }

    /** The stored mapping, or null before one was accepted. */
    public function mapping(): ?Mapping
    {
        $data = $this->metaJson('mapping');

        return $data === null ? null : Mapping::fromArray($data);
    }

    /** @return list<ColumnStats> one per source column; empty before reading starts */
    public function stats(): array
    {
        $data = $this->metaJson('stats');
        if ($data === null) {
            return [];
        }
        $stats = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                throw new \UnexpectedValueException('Column statistics are objects.');
            }
            $stats[] = ColumnStats::fromArray($entry);
        }

        return $stats;
    }

    /** @param list<ColumnStats> $stats */
    public function setStats(array $stats): void
    {
        $this->setMetaJson('stats', array_map(static fn (ColumnStats $s): array => $s->toArray(), $stats));
    }

    /** The uploaded file as the plugin sees it. */
    public function sourceFile(): SourceFile
    {
        return new SourceFile(
            $this->folder . '/' . self::UPLOAD,
            (string) $this->meta('extension'),
            $this->metaInt('file_size'),
            $this->folder . '/' . StagingArea::TEMP,
        );
    }

    /** Stored data rows (not empty rows). */
    public function rowCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM rows');
    }

    /** @return array{empty: int, over_limit: int} */
    public function skipped(): array
    {
        $data = $this->metaJson('skipped') ?? [];

        return [
            'empty' => is_int($data['empty'] ?? null) ? $data['empty'] : 0,
            'over_limit' => is_int($data['over_limit'] ?? null) ? $data['over_limit'] : 0,
        ];
    }

    private static function keyOf(string $folder): string
    {
        $key = basename($folder);
        if (!Keys::isValid($key)) {
            throw new \InvalidArgumentException('A job folder is named by a key.');
        }

        return $key;
    }

    private static function checkName(string $name): void
    {
        if (!in_array($name, self::META_KEYS, true)) {
            throw new \InvalidArgumentException('Unknown job meta key.');
        }
    }

    private static function checkMeta(string $name, mixed $value): void
    {
        self::checkName($name);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Job meta values are strings.');
        }
    }
}
