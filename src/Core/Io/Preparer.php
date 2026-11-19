<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;

/**
 * Converts stored rows to prepared records (M3 spec section 6.5) in
 * chunks, and computes the review warnings (preview, section 6.4). Writes
 * only the job file. Rows are taken in `n` order after the last prepared
 * one; `prepared_rows` counts them for the progress bar. Each included
 * column's value is the field type's lenient parse (Import mode) with the
 * field's options, or empty with an issue; link cells become title lists
 * matched at commit. A row whose included cells are all empty after
 * conversion is stored with skip `empty` (its issues are not recorded:
 * nothing of it is imported). No row is skipped for missing
 * required values (P118).
 */
final class Preparer
{
    private readonly \Closure $monotonic;

    /**
     * @param (\Closure(): int)|null $monotonic nanoseconds (default hrtime); a test seam
     * @internal $chunkRows is a test seam
     */
    public function __construct(
        private readonly FieldTypes $types,
        ?\Closure $monotonic = null,
        private readonly int $chunkRows = IoLimits::CHUNK_ROWS,
    ) {
        $this->monotonic = $monotonic ?? static fn (): int => (int) hrtime(true);
        if ($chunkRows < 1) {
            throw new \InvalidArgumentException('The chunk size is positive.');
        }
    }

    /** Prepares one chunk; true when every stored row is prepared (the job is then Ready). The caller holds the lock. */
    public function chunk(ImportJob $job, Schema $schema): bool
    {
        if (!$job->isLocked()) {
            throw new \LogicException('Take the job lock first.');
        }
        if ($job->state() !== JobState::Preparing) {
            throw new \LogicException('The job is not preparing.');
        }
        $mapping = $job->mapping();
        if ($mapping === null) {
            throw new \LogicException('The job has no mapping.');
        }
        $plans = ColumnPlan::forMapping($mapping, $schema, $this->types, $job->stats());
        $clock = $this->monotonic;
        $deadline = $clock() + IoLimits::CHUNK_SECONDS * 1000000000;

        return $job->db()->write(function (Database $db) use ($job, $plans, $clock, $deadline): bool {
            $prepared = (int) $db->value('SELECT COUNT(*) FROM prepared');
            $rows = $db->all(
                'SELECT n, cells FROM rows WHERE n > COALESCE((SELECT MAX(n) FROM prepared), 0) ORDER BY n LIMIT ?',
                [$this->chunkRows],
            );
            $done = count($rows) < $this->chunkRows;
            foreach ($rows as $index => $row) {
                $n = (int) $row['n'];
                [$record, $skip, $issues] = self::convert(self::decode((string) $row['cells']), $plans);
                $db->run('INSERT INTO prepared (n, record, skip) VALUES (?, ?, ?)', [
                    $n,
                    $record === null ? null : json_encode((object) $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    $skip,
                ]);
                if ($skip === null) {
                    foreach ($issues as $column => $kind) {
                        $db->run('INSERT OR IGNORE INTO issues (n, col, kind) VALUES (?, ?, ?)', [$n, $column, $kind]);
                    }
                }
                $prepared++;
                if ($index + 1 < count($rows) && $clock() >= $deadline) {
                    $done = false;
                    break;
                }
            }
            if ($done) {
                $done = (int) $db->value('SELECT COUNT(*) FROM rows WHERE n > COALESCE((SELECT MAX(n) FROM prepared), 0)') === 0;
            }
            $job->setMeta('prepared_rows', (string) $prepared);
            if ($done) {
                $job->moveTo(JobState::Ready);
            }

            return $done;
        });
    }

    /**
     * The review warnings over the first $limit stored rows, without
     * writing: the same conversion as chunk(), plus title lookups in the
     * project ($project, read only) for link columns.
     */
    public function preview(ImportJob $job, Mapping $mapping, Schema $schema, Database $project, int $limit = IoLimits::PREVIEW_ROWS): PreviewResult
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The preview needs at least one row.');
        }
        $plans = ColumnPlan::forMapping($mapping, $schema, $this->types, $job->stats());
        $rows = $job->db()->all('SELECT n, cells FROM rows ORDER BY n LIMIT ?', [$limit]);
        $total = $job->rowCount();
        $emptied = [];
        $titles = [];
        $import = 0;
        foreach ($rows as $row) {
            [$record, $skip, $issues] = self::convert(self::decode((string) $row['cells']), $plans);
            if ($skip !== null || $record === null) {
                continue;
            }
            foreach ($issues as $column => $kind) {
                $emptied[$column] = ($emptied[$column] ?? 0) + 1;
            }
            $import++;
            foreach ($plans as $plan) {
                if ($plan->isLink()) {
                    $value = $record[$plan->key] ?? null;
                    foreach (is_array($value) && is_array($value['titles'] ?? null) ? $value['titles'] : [] as $title) {
                        $titles[$plan->column][strtolower(Text::trim((string) $title))] ??= (string) $title;
                    }
                }
            }
        }
        $added = [];
        $refused = [];
        foreach ($plans as $plan) {
            if (!$plan->isLink() || $plan->target === null) {
                continue;
            }
            $existing = Titles::load($project, $schema, $plan->target);
            foreach ($titles[$plan->column] ?? [] as $folded => $title) {
                if (isset($existing->ids[$folded])) {
                    continue;
                }
                if (Titles::parse($this->types, $plan->target, $title) === null) {
                    $refused[$plan->column] = ($refused[$plan->column] ?? 0) + 1;
                } else {
                    $added[$plan->column] = ($added[$plan->column] ?? 0) + 1;
                }
            }
        }

        return new PreviewResult(count($rows), $total > $limit, $emptied, $added, $refused, $import);
    }

    /**
     * One row: [record or null when skipped, skip reason or null, issues by column].
     *
     * @param list<?string> $cells
     * @param list<ColumnPlan> $plans
     * @return array{0: array<string, mixed>|null, 1: ?string, 2: array<int, string>}
     */
    public static function convert(array $cells, array $plans): array
    {
        $record = [];
        $issues = [];
        $any = false;
        foreach ($plans as $plan) {
            [$value, $hasValue, $issue] = $plan->convert($cells[$plan->column] ?? null);
            $record[$plan->key] = $value;
            $any = $any || $hasValue;
            if ($issue !== null) {
                $issues[$plan->column] = $issue;
            }
        }
        if (!$any) {
            return [null, 'empty', $issues];
        }

        return [$record, null, $issues];
    }

    /** @return list<?string> */
    private static function decode(string $json): array
    {
        $cells = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($cells) || !array_is_list($cells)) {
            throw new \UnexpectedValueException('A staged row is not a list of cells.');
        }
        foreach ($cells as $cell) {
            if ($cell !== null && !is_string($cell)) {
                throw new \UnexpectedValueException('A staged cell is not text.');
            }
        }

        return $cells;
    }
}
