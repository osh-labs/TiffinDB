<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceTable;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Database;

/**
 * The staging read loop (M3 spec section 6.2, P139). One call reads one
 * chunk: at most CHUNK_ROWS rows yielded by the plugin or CHUNK_SECONDS of
 * time on the monotonic seam, whichever comes first, and appends them to
 * the job file in one job-file transaction together with the updated
 * column statistics and counters.
 *
 * Row numbers: `read_rows` counts every row the plugin has yielded (empty
 * ones included), so `from` always counts in the plugin's own numbering; a
 * stored row's `n` is its 1-based data row number in the file. Empty rows
 * (every cell null or blank) are counted as skipped and not stored. A cell
 * longer than MAX_CELL_CHARS characters is stored as null with issue
 * `too-long`; cells past the column count are dropped with one issue
 * `extra-cells` per row (col = the column count); invalid UTF-8 is
 * replaced by U+FFFD and counted (`replaced`).
 *
 * Caps: after MAX_ROWS stored rows, the next non-empty row stops reading
 * and is counted as over the limit (so the count is "at least one"); after
 * MAX_SOURCE_ROWS yielded rows reading stops, and when the last request was
 * filled the rest of the file is counted the same way. A plugin that
 * yields more rows than asked has the excess ignored.
 *
 * Plugin failures: any Throwable from the plugin rolls the chunk back,
 * moves the job to Failed (reason `plugin-<reason>`), and is rethrown as a
 * PluginFailure (others wrapped as REASON_FAILED).
 */
final class Reader
{
    private readonly \Closure $monotonic;

    /**
     * @param (\Closure(): int)|null $monotonic nanoseconds (default hrtime); a test seam
     * @internal $chunkRows, $maxRows, $maxSourceRows are test seams; application code uses the defaults
     */
    public function __construct(
        private readonly FieldTypes $types,
        ?\Closure $monotonic = null,
        private readonly int $chunkRows = IoLimits::CHUNK_ROWS,
        private readonly int $maxRows = IoLimits::MAX_ROWS,
        private readonly int $maxSourceRows = IoLimits::MAX_SOURCE_ROWS,
    ) {
        $this->monotonic = $monotonic ?? static fn (): int => (int) hrtime(true);
        if ($chunkRows < 1 || $maxRows < 1 || $maxSourceRows < 1) {
            throw new \InvalidArgumentException('Reader limits are positive.');
        }
    }

    /**
     * Reads one chunk of table $tableIndex of the job's file. Returns true
     * when reading is done (the job is then in Review). The caller holds
     * the job lock.
     */
    public function chunk(ImportJob $job, Importer $importer, Settings $settings, SourceTable $table): bool
    {
        if (!$job->isLocked()) {
            throw new \LogicException('Take the job lock first.');
        }
        if ($job->state() !== JobState::Reading) {
            throw new \LogicException('The job is not reading.');
        }
        $clock = $this->monotonic;
        $deadline = $clock() + IoLimits::CHUNK_SECONDS * 1000000000;
        $columns = count($table->columns);
        $tableIndex = $job->metaInt('table_index');
        $file = $job->sourceFile();

        try {
            return $job->db()->write(function (Database $db) use ($job, $importer, $settings, $tableIndex, $file, $columns, $clock, $deadline): bool {
                $stats = $job->stats();
                for ($c = count($stats); $c < $columns; $c++) {
                    $stats[] = new ColumnStats();
                }
                $read = $job->metaInt('read_rows');
                $stored = $job->rowCount();
                $skipped = $job->skipped();
                $replaced = $job->metaInt('replaced');
                $inChunk = 0;
                $done = false;
                while (!$done && $inChunk < $this->chunkRows && $clock() < $deadline) {
                    $limit = min($this->chunkRows - $inChunk, $this->maxRows - $stored + 1, $this->maxSourceRows - $read);
                    if ($limit < 1) {
                        $done = true;
                        break;
                    }
                    $got = 0;
                    $timedOut = false;
                    foreach ($importer->rows($file, $settings, $tableIndex, $read, $limit) as $row) {
                        if ($got >= $limit) {
                            break;
                        }
                        $got++;
                        $read++;
                        $inChunk++;
                        [$cells, $bad] = $this->cells($row, $columns, $db, $read);
                        $replaced += $bad;
                        if (self::isEmpty($cells)) {
                            $skipped['empty']++;
                        } elseif ($stored >= $this->maxRows) {
                            $skipped['over_limit']++;
                            $done = true;
                            break;
                        } else {
                            $db->run('INSERT INTO rows (n, cells) VALUES (?, ?)', [$read, self::encode($cells)]);
                            $stored++;
                            foreach ($cells as $index => $cell) {
                                $stats[$index]->add($cell, $this->types);
                            }
                        }
                        if ($got < $limit && $clock() >= $deadline) {
                            $timedOut = true;
                            break;
                        }
                    }
                    if (!$done && !$timedOut && $got < $limit) {
                        // The plugin returned fewer rows than asked: the end of the table.
                        $done = true;
                    }
                    if (!$done && $read >= $this->maxSourceRows) {
                        // P139: no more rows are read; a filled last request means more may follow.
                        $done = true;
                        $skipped['over_limit'] = max(1, $skipped['over_limit']);
                    }
                }
                $job->setStats($stats);
                $job->setMeta('read_rows', (string) $read);
                $job->setMetaJson('skipped', $skipped);
                $job->setMeta('replaced', (string) $replaced);
                if ($done) {
                    $job->setMeta('read_done', '1');
                    $job->moveTo(JobState::Review);
                }

                return $done;
            });
        } catch (PluginFailure $failure) {
            $job->fail('plugin-' . $failure->reason);

            throw $failure;
        } catch (\Throwable $error) {
            $job->fail('plugin-' . PluginFailure::REASON_FAILED);
            error_log('TiffinDB: an import plugin failed while reading (' . $error::class . ').');

            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin failed while reading.', $error);
        }
    }

    /**
     * The row as exactly $columns cells of ?string, with issues for long
     * cells and extra cells, and the number of replaced characters.
     *
     * @return array{0: list<?string>, 1: int}
     */
    private function cells(mixed $row, int $columns, Database $db, int $n): array
    {
        $replaced = 0;
        if (!is_array($row)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'A row is not a list of cells.');
        }
        $cells = [];
        $index = 0;
        foreach ($row as $cell) {
            if ($index >= $columns) {
                $db->run('INSERT OR IGNORE INTO issues (n, col, kind) VALUES (?, ?, ?)', [$n, $columns, 'extra-cells']);
                break;
            }
            if ($cell !== null && !is_string($cell)) {
                throw new PluginFailure(PluginFailure::REASON_FAILED, 'A cell is not text.');
            }
            if (is_string($cell) && !Text::isUtf8($cell)) {
                $fixed = json_decode(json_encode($cell, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true, 2, JSON_THROW_ON_ERROR);
                $replaced += substr_count((string) $fixed, "\u{FFFD}") - substr_count($cell, "\u{FFFD}");
                $cell = (string) $fixed;
            }
            if (is_string($cell) && strlen($cell) > IoLimits::MAX_CELL_CHARS && Text::length($cell) > IoLimits::MAX_CELL_CHARS) {
                $db->run('INSERT OR IGNORE INTO issues (n, col, kind) VALUES (?, ?, ?)', [$n, $index, 'too-long']);
                $cell = null;
            }
            $cells[] = $cell;
            $index++;
        }
        for (; $index < $columns; $index++) {
            $cells[] = null;
        }

        return [$cells, $replaced];
    }

    /** @param list<?string> $cells */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && Text::trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<?string> $cells */
    private static function encode(array $cells): string
    {
        return json_encode($cells, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
