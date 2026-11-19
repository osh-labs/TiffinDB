<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * One CSV file read with one dialect, as the import plugin needs it (M3
 * spec section 8.1; a helper of this namespace for `plugins/import/csv`):
 * the column headings, and data rows from any data row on.
 *
 * Columns: the heading row's cells (empty headings become "Column n"), or
 * "Column 1" to "Column n" for the widest of the first WIDTH_RECORDS
 * records when there is no heading row. More than MAX_COLUMNS columns, or
 * a file with no record at all, is a PluginFailure.
 *
 * The figures are those of IoLimits (MAX_COLUMNS, CHUNK_ROWS, MAX_SOURCE_ROWS),
 * repeated because this namespace uses only the contract; a test keeps
 * them equal.
 *
 * Rows: each record as exactly count(columns) cells, '' read as null,
 * shorter records padded with null. A record with a non-empty field past
 * the last column gets one more (null) cell, so the core's read loop
 * records issue `extra-cells` for it and drops the rest. At most
 * MAX_ROWS_PER_CALL rows per call (P139). For UTF-8 files ill-formed bytes
 * are passed on in the cells, so that the core replaces and counts them
 * (the review warning, section 8.1); other character sets are decoded here.
 *
 * Resumption: the byte position of every CHECKPOINT_EVERY-th record is kept
 * in `offsets.json` in the temporary folder, for this file and dialect
 * only; rows() starts from the nearest one before the first record asked
 * for. Without the cache (none given, unwritable, or not matching the
 * file), it reads from the start.
 */
final class CsvFile
{
    public const MAX_COLUMNS = 200;
    public const WIDTH_RECORDS = 1000;
    public const MAX_ROWS_PER_CALL = 2000;
    public const CHECKPOINT_EVERY = 2000;
    /** Records beyond this are never asked for (the core reads at most MAX_SOURCE_ROWS), so no checkpoint is kept for them. */
    public const CHECKPOINT_LIMIT = 102000;
    public const CACHE_FILE = 'offsets.json';
    public const CACHE_MAX_BYTES = 65536;

    /** Generated headings are "Column 1", "Column 2", ... */
    public const COLUMN = 'Column ';

    /** Four bytes per character of the longest cell, as SourceTable allows. */
    private const MAX_HEADING_BYTES = 80000;

    /** @var list<string>|null */
    private ?array $columns = null;

    public function __construct(
        private readonly string $path,
        private readonly CsvDialect $dialect,
        private readonly ?string $tempFolder = null,
        private readonly int $maxRecordBytes = CsvReader::MAX_RECORD_BYTES,
    ) {
    }

    /** @return list<string> 1 to MAX_COLUMNS headings */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $reader = $this->reader(null, self::MAX_COLUMNS + 1);
        $first = $reader->next();
        if ($first === null) {
            throw new PluginFailure(PluginFailure::REASON_UNREADABLE, 'The file holds no records.');
        }
        if ($this->dialect->heading) {
            if ($reader->fieldCount() > self::MAX_COLUMNS) {
                throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'The heading row has more than the column limit.');
            }
            $columns = [];
            foreach ($first as $index => $heading) {
                $heading = Charset::cut(Charset::scrub($heading), self::MAX_HEADING_BYTES);
                $columns[] = trim($heading, " \t\n") === '' ? self::COLUMN . ($index + 1) : $heading;
            }
        } else {
            $width = $reader->fieldCount();
            while ($width <= self::MAX_COLUMNS && $reader->records() < self::WIDTH_RECORDS && $reader->next() !== null) {
                $width = max($width, $reader->fieldCount());
            }
            if ($width > self::MAX_COLUMNS) {
                throw new PluginFailure(PluginFailure::REASON_TOO_LARGE, 'A record has more than the column limit.');
            }
            $columns = [];
            for ($i = 1; $i <= $width; $i++) {
                $columns[] = self::COLUMN . $i;
            }
        }
        $reader->close();

        return $this->columns = $columns;
    }

    /**
     * Data rows from data row $from (0-based, the heading row not counted),
     * at most $limit (and MAX_ROWS_PER_CALL) of them.
     *
     * @return \Generator<int, list<?string>>
     */
    public function rows(int $from, int $limit): \Generator
    {
        $limit = min($limit, self::MAX_ROWS_PER_CALL);
        if ($from < 0 || $limit < 1) {
            return;
        }
        $width = count($this->columns());
        $target = $from + ($this->dialect->heading ? 1 : 0);
        $cache = $this->loadCache();
        $startAt = 0;
        $checkpoint = null;
        foreach ($cache as $ordinal => $offsets) {
            if ($ordinal <= $target && $ordinal > $startAt) {
                $startAt = $ordinal;
                $checkpoint = $offsets;
            }
        }
        $reader = $this->reader($checkpoint, $width);
        $ordinal = $startAt;
        $added = false;
        try {
            $given = 0;
            while ($given < $limit) {
                $fields = $reader->next();
                if ($fields === null) {
                    break;
                }
                if ($ordinal > 0 && $ordinal % self::CHECKPOINT_EVERY === 0 && $ordinal <= self::CHECKPOINT_LIMIT && !isset($cache[$ordinal])) {
                    $cache[$ordinal] = $reader->checkpoint();
                    $added = true;
                }
                $ordinal++;
                if ($ordinal <= $target) {
                    continue;
                }
                $cells = [];
                foreach ($fields as $field) {
                    $cells[] = $field === '' ? null : $field;
                }
                for ($i = count($cells); $i < $width; $i++) {
                    $cells[] = null;
                }
                if ($reader->hadExtra()) {
                    $cells[] = null;
                }
                $given++;

                yield $cells;
            }
        } finally {
            $reader->close();
            if ($added) {
                $this->saveCache($cache);
            }
        }
    }

    /** @param array{0: int, 1: int}|null $from */
    private function reader(?array $from, int $maxFields): CsvReader
    {
        return new CsvReader($this->path, $this->dialect, $maxFields, $this->maxRecordBytes, $from, true);
    }

    /** The identity of this file and dialect, so a cache made for another one is never used. */
    private function cacheKey(): string
    {
        clearstatcache(true, $this->path);
        $size = filesize($this->path);
        $time = filemtime($this->path);

        return hash('sha256', json_encode([$this->dialect->charset, $this->dialect->delimiter, $size, $time, $this->maxRecordBytes], JSON_THROW_ON_ERROR));
    }

    private function cachePath(): ?string
    {
        if ($this->tempFolder === null || !is_dir($this->tempFolder) || is_link($this->tempFolder)) {
            return null;
        }

        return $this->tempFolder . '/' . self::CACHE_FILE;
    }

    /** @return array<int, array{0: int, 1: int}> record ordinal => checkpoint */
    private function loadCache(): array
    {
        $path = $this->cachePath();
        if ($path === null || !is_file($path) || is_link($path)) {
            return [];
        }
        try {
            $text = CsvReader::quietly(static fn () => file_get_contents($path, false, null, 0, self::CACHE_MAX_BYTES + 1), 'The offset cache could not be read.');
            if (!is_string($text) || strlen($text) > self::CACHE_MAX_BYTES) {
                return [];
            }
            $data = json_decode($text, true, 4, JSON_THROW_ON_ERROR);
        } catch (\RuntimeException | \JsonException) {
            return [];
        }
        if (!is_array($data) || ($data['key'] ?? null) !== $this->cacheKey() || !is_array($data['at'] ?? null)) {
            return [];
        }
        $size = (int) filesize($this->path);
        $cache = [];
        foreach ($data['at'] as $ordinal => $offsets) {
            if (!is_int($ordinal) && !(is_string($ordinal) && preg_match('/^[1-9][0-9]{0,8}\z/', $ordinal) === 1)) {
                continue;
            }
            $ordinal = (int) $ordinal;
            if ($ordinal % self::CHECKPOINT_EVERY !== 0 || $ordinal > self::CHECKPOINT_LIMIT || !is_array($offsets) || !array_is_list($offsets) || count($offsets) !== 2) {
                continue;
            }
            [$source, $skip] = $offsets;
            if (!is_int($source) || !is_int($skip) || $source < 0 || $source > $size || $skip < 0 || $skip > $this->maxRecordBytes + 4 * CsvReader::CHUNK_BYTES) {
                continue;
            }
            $cache[$ordinal] = $offsets;
        }

        return $cache;
    }

    /** @param array<int, array{0: int, 1: int}> $cache */
    private function saveCache(array $cache): void
    {
        $path = $this->cachePath();
        if ($path === null) {
            return;
        }
        ksort($cache);
        $text = json_encode(['key' => $this->cacheKey(), 'at' => (object) $cache], JSON_THROW_ON_ERROR);
        $temp = $path . '.new';
        try {
            CsvReader::quietly(static function () use ($temp, $path, $text): void {
                if (is_link($temp) || is_link($path)) {
                    return;
                }
                if (file_put_contents($temp, $text) === strlen($text)) {
                    rename($temp, $path);
                }
            }, 'The offset cache could not be written.');
        } catch (\RuntimeException) {
            // The cache is only a shortcut: reading from the start gives the same rows.
        }
    }
}
