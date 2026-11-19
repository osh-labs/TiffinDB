<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Query\InputMode;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\SnapshotReason;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The import commit (M3 spec section 6.6; security-critical: atomicity).
 *
 * 1. The target hash is recomputed and compared with the one stored when
 *    the mapping was accepted; a difference sends the job back to Review
 *    with problem.import.target_changed, before any snapshot.
 * 2. An `import` snapshot is taken (outside any transaction).
 * 3. One Database::write() on the project: first the import log is checked
 *    for the job key (an earlier commit whose clean-up did not finish: the
 *    transaction rolls back and the earlier report is returned) and the
 *    target hash is checked again; then the new table and its fields and
 *    relations (joined SchemaEditor, in column order), the link targets
 *    not found (created once per title, ASCII case-insensitive), the
 *    records in file order with their links, the log row, and
 *    ProjectFile::touch(). Any exception rolls everything back.
 * 4. The job moves to Done with the report key in its meta; the caller
 *    audits and removes the job folder.
 *
 * Job states on failure: target changed, Review; Busy, stays Ready (the
 * Admin may retry); anything else, Failed with reason `commit-failed`.
 *
 * Fields of a new table are added in source column order, link columns
 * included, so that the table's field order follows the file (the spec's
 * sketch adds relations after the other fields; the order is otherwise
 * the same).
 */
final class Committer
{
    public const ISSUE_KINDS_EMPTIED = ['too-long', 'unreadable', 'not-a-choice', 'link-unreadable', 'link-taken', 'link-too-many'];
    private const PAGE = 1000;

    private readonly \Closure $fault;

    /**
     * @param (\Closure(string): void)|null $fault test seam, called inside the transaction after each sub-step
     *     ('table', 'fields', 'links', 'rows-half', 'rows', 'log') so a test can inject a failure there
     */
    public function __construct(?\Closure $fault = null)
    {
        $this->fault = $fault ?? static function (string $step): void {
        };
    }

    /**
     * Commits a Ready job into the project. The caller holds the job lock.
     *
     * @param (\Closure(SnapshotInfo): void)|null $onSnapshot told of the import snapshot
     * @throws ValidationFailed problem.import.target_changed (the job is back in Review)
     */
    public function commit(
        ImportJob $job,
        Mapping $mapping,
        Database $db,
        FieldTypes $types,
        Clock $clock,
        SchemaEditor $editor,
        Snapshots $snapshots,
        string $projectFolder,
        string $userKey,
        ?\Closure $onSnapshot = null,
    ): ImportReport {
        if (!$job->isLocked()) {
            throw new \LogicException('Take the job lock first.');
        }
        if ($job->state() !== JobState::Ready) {
            throw new \LogicException('The job is not ready to commit.');
        }
        $stored = $job->mapping();
        if ($stored === null || $stored->toArray() !== $mapping->toArray()) {
            throw new \LogicException('The mapping is not the job\'s accepted mapping.');
        }
        if (!Keys::isValid($userKey)) {
            throw new \InvalidArgumentException('The person key must be a valid key.');
        }
        $existing = ImportLog::get($db, $job->key());
        if ($existing !== null) {
            return $this->finish($job, $existing);
        }
        if (!hash_equals((string) $job->meta('schema_hash'), self::schemaHash($db, $mapping))) {
            $job->moveTo(JobState::Review);

            throw new ValidationFailed([new Problem('table', 'problem.import.target_changed')]);
        }
        $requiredEmpty = $mapping->target === Mapping::EXISTING ? $this->requiredEmpty($job, $mapping, new Schema($db, $types)) : [];

        $snapshot = $snapshots->take($db, $projectFolder, SnapshotReason::Import);
        if ($onSnapshot !== null) {
            $onSnapshot($snapshot);
        }

        try {
            $report = $db->write(fn (Database $db): ?ImportReport => $this->apply(
                $job, $mapping, $db, $types, $clock, $editor, $userKey, $snapshot->file, $requiredEmpty,
            ));
        } catch (ValidationFailed $failed) {
            if (self::isTargetChanged($failed)) {
                $job->moveTo(JobState::Review);
            } else {
                $job->fail('commit-failed');
            }

            throw $failed;
        } catch (Busy $busy) {
            throw $busy;
        } catch (\Throwable $error) {
            $job->fail('commit-failed');

            throw $error;
        }
        if ($report === null) {
            // The log row appeared between the first check and the write lock: an earlier commit finished.
            $report = ImportLog::get($db, $job->key());
            if ($report === null) {
                throw new \LogicException('The import log row vanished.');
            }
        }

        return $this->finish($job, $report);
    }

    /**
     * The target hash (section 6.6 step 1): SHA-256 of the JSON of the
     * metadata rows a commit depends on. Existing table: its field rows
     * `[key, type, options, position]`, the relations touching it, and the
     * title fields of the tables its relations link to. New table: every
     * table name, and the title fields of the link targets the mapping
     * names.
     */
    public static function schemaHash(Database $db, Mapping $mapping): string
    {
        $parts = [];
        $linkTables = [];
        if ($mapping->target === Mapping::EXISTING) {
            $key = (string) $mapping->tableKey;
            $parts['table'] = $db->all('SELECT "key", "title_field" FROM "_tiffin_tables" WHERE "key" = ?', [$key]);
            $parts['fields'] = $db->all('SELECT "key", "type", "options", "position" FROM "_tiffin_fields" WHERE "table_key" = ? ORDER BY "key"', [$key]);
            $parts['relations'] = $db->all(
                'SELECT "key", "left_table", "left_field", "left_max", "right_table", "right_field", "right_max" FROM "_tiffin_relations"'
                . ' WHERE "left_table" = ? OR "right_table" = ? ORDER BY "key"',
                [$key, $key],
            );
            foreach ($parts['relations'] as $relation) {
                $linkTables[] = (string) $relation['left_table'];
                $linkTables[] = (string) $relation['right_table'];
            }
        } else {
            $parts['tables'] = $db->all('SELECT "key", "name" FROM "_tiffin_tables" ORDER BY "key"');
            foreach ($mapping->included() as $column) {
                $type = (string) $column->type;
                if (str_starts_with($type, Inference::LINK_PREFIX)) {
                    $linkTables[] = substr($type, strlen(Inference::LINK_PREFIX));
                }
            }
        }
        $linkTables = array_values(array_unique($linkTables));
        sort($linkTables);
        $parts['titles'] = [];
        foreach ($linkTables as $tableKey) {
            $parts['titles'][] = $db->all(
                'SELECT t."key", t."title_field", f."type", f."options" FROM "_tiffin_tables" AS t'
                . ' LEFT JOIN "_tiffin_fields" AS f ON f."key" = t."title_field" WHERE t."key" = ?',
                [$tableKey],
            );
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Step 3, inside the project transaction. Null when the log already
     * holds the job key (nothing is written; the caller rolls back by
     * returning normally, since nothing was changed).
     *
     * @param list<array{field: string, count: int}> $requiredEmpty
     */
    private function apply(
        ImportJob $job,
        Mapping $mapping,
        Database $db,
        FieldTypes $types,
        Clock $clock,
        SchemaEditor $editor,
        string $userKey,
        string $snapshotFile,
        array $requiredEmpty,
    ): ?ImportReport {
        $fault = $this->fault;
        if (ImportLog::has($db, $job->key())) {
            return null;
        }
        if (!hash_equals((string) $job->meta('schema_hash'), self::schemaHash($db, $mapping))) {
            throw new ValidationFailed([new Problem('table', 'problem.import.target_changed')]);
        }
        $stats = $job->stats();
        $plans = ColumnPlan::forMapping($mapping, new Schema($db, $types), $types, $stats);

        // 3.1: the new table, its fields and relations, and its title field.
        $keys = [];
        $newTable = $mapping->target === Mapping::NEW;
        if ($newTable) {
            $joined = $editor->within();
            $name = Text::trim((string) $mapping->newTableName);
            $itemName = Text::trim(ColumnStats::cut(strtolower($name), SchemaEditor::MAX_ITEM_NAME));
            $table = $joined->createTable($name, $itemName === '' ? $name : $itemName, false);
            $tableKey = $table->key;
            $fault('table');
            $titleKey = null;
            foreach ($mapping->included() as $column) {
                $plan = self::planOf($plans, $column->column);
                if ($plan->isLink() && $plan->target !== null) {
                    $reverse = self::reverseName($db, $types, $plan->target->tableKey, $name);
                    $field = $joined->addRelation($tableKey, (string) $column->fieldName, $plan->target->tableKey, Cardinality::Many, Cardinality::Many, $reverse, false, '');
                } else {
                    $field = $joined->addField($tableKey, (string) $column->fieldName, $plan->type, Inference::optionsFor($plan->type, $stats[$column->column] ?? new ColumnStats()), false, '');
                    if ($titleKey === null && $plan->type === 'text') {
                        $titleKey = $field->key;
                    }
                }
                $keys[$plan->key] = $field->key;
            }
            $fault('fields');
            if ($titleKey !== null) {
                $joined->updateTable($tableKey, $name, $itemName === '' ? $name : $itemName, $titleKey);
            }
        } else {
            $tableKey = (string) $mapping->tableKey;
            foreach ($plans as $plan) {
                $keys[$plan->key] = $plan->key;
            }
        }

        // 3.2: Schema and Records that see what 3.1 made.
        $schema = new Schema($db, $types);
        $records = (new Records($db, $schema, $types, $clock))->within();

        // 3.3: link targets.
        $maps = [];
        $refused = [];
        $linksAdded = [];
        $duplicates = [];
        foreach ($plans as $plan) {
            if (!$plan->isLink() || $plan->target === null) {
                continue;
            }
            $titles = Titles::load($db, $schema, $plan->target);
            $duplicates[$plan->key] = $titles->duplicates;
            $map = $titles->ids;
            $refused[$plan->key] = [];
            $linksAdded[$plan->key] = 0;
            foreach ($this->preparedRows($job) as $record) {
                $value = $record[$plan->key] ?? null;
                foreach (is_array($value) && is_array($value['titles'] ?? null) ? $value['titles'] : [] as $title) {
                    $title = (string) $title;
                    $folded = Titles::fold($title);
                    if ($folded === '' || isset($map[$folded]) || isset($refused[$plan->key][$folded])) {
                        continue;
                    }
                    if (Titles::parse($types, $plan->target, $title) === null) {
                        $refused[$plan->key][$folded] = true;

                        continue;
                    }
                    $map[$folded] = $records->create($plan->target->tableKey, [$plan->target->titleField->key => $title], InputMode::Import, $userKey, []);
                    $linksAdded[$plan->key]++;
                }
            }
            $maps[$plan->key] = $map;
        }
        $fault('links');

        // 3.4: the records, in file order, and their links.
        $taken = [];
        foreach ($plans as $plan) {
            if ($plan->isLink()) {
                $field = $schema->field($keys[$plan->key]);
                $relation = $schema->relationOf($field);
                if ($relation !== null && $relation->maxFor($relation->otherField($field->key)) === Cardinality::One) {
                    $taken[$plan->key] = [];
                    $otherSide = $relation->sideOf($field->key) === 'left' ? 'right_id' : 'left_id';
                    foreach ($db->all('SELECT DISTINCT ' . Identifier::system($otherSide) . ' AS "id" FROM ' . Identifier::joinTable($relation->key)) as $row) {
                        $taken[$plan->key][(int) $row['id']] = true;
                    }
                }
            }
        }
        $linkIssues = [];
        $imported = 0;
        $total = (int) $job->db()->value('SELECT COUNT(*) FROM prepared WHERE skip IS NULL');
        $halfDone = false;
        foreach ($this->preparedRows($job) as $n => $record) {
            $values = [];
            foreach ($plans as $plan) {
                if ($plan->isLink()) {
                    continue;
                }
                $field = $schema->field($keys[$plan->key]);
                $values[$field->key] = $types->get($field->type)->fromStorage($record[$plan->key] ?? null, $field->options);
            }
            $id = $records->create($tableKey, $values, InputMode::Canonical, $userKey, []);
            foreach ($plans as $plan) {
                if (!$plan->isLink()) {
                    continue;
                }
                $value = $record[$plan->key] ?? null;
                $ids = [];
                foreach (is_array($value) && is_array($value['titles'] ?? null) ? $value['titles'] : [] as $title) {
                    $folded = Titles::fold((string) $title);
                    if (isset($maps[$plan->key][$folded])) {
                        $ids[] = $maps[$plan->key][$folded];
                    } elseif ($folded !== '') {
                        $linkIssues[$plan->column][$n] ??= 'link-unreadable';
                    }
                }
                $ids = array_values(array_unique($ids));
                if (count($ids) > Records::MAX_LINK_IDS) {
                    $ids = array_slice($ids, 0, Records::MAX_LINK_IDS);
                    $linkIssues[$plan->column][$n] ??= 'link-too-many';
                }
                if (isset($taken[$plan->key])) {
                    $kept = [];
                    foreach ($ids as $target) {
                        if (isset($taken[$plan->key][$target])) {
                            $linkIssues[$plan->column][$n] ??= 'link-taken';
                        } else {
                            $kept[] = $target;
                            $taken[$plan->key][$target] = true;
                        }
                    }
                    $ids = $kept;
                }
                if ($ids !== []) {
                    $records->setLinks($keys[$plan->key], $id, $ids, $userKey);
                }
            }
            $imported++;
            if (!$halfDone && $imported * 2 >= $total) {
                $halfDone = true;
                $fault('rows-half');
            }
        }
        $fault('rows');

        // 3.5 and 3.6: the log row, and the project meta.
        $report = $this->report($job, $mapping, $schema, $plans, $keys, $tableKey, $newTable, $imported, $linksAdded, $duplicates, $linkIssues, $requiredEmpty, $userKey, $clock, $snapshotFile);
        ImportLog::insert($db, $report);
        $fault('log');
        ProjectFile::touch($db, $userKey, $clock);

        return $report;
    }

    /**
     * @param list<ColumnPlan> $plans
     * @param array<string, string> $keys plan key => field key
     * @param array<string, int> $linksAdded
     * @param array<string, int> $duplicates
     * @param array<int, array<int, string>> $linkIssues column => n => kind
     * @param list<array{field: string, count: int}> $requiredEmpty
     */
    private function report(
        ImportJob $job,
        Mapping $mapping,
        Schema $schema,
        array $plans,
        array $keys,
        string $tableKey,
        bool $newTable,
        int $imported,
        array $linksAdded,
        array $duplicates,
        array $linkIssues,
        array $requiredEmpty,
        string $userKey,
        Clock $clock,
        string $snapshotFile,
    ): ImportReport {
        $skipped = $job->skipped();
        $skipped['empty'] += (int) $job->db()->value('SELECT COUNT(*) FROM prepared WHERE skip IS NOT NULL');
        $columns = [];
        $emptiedTotal = 0;
        foreach ($plans as $plan) {
            $rows = [];
            foreach ($job->db()->all(
                'SELECT i.n AS n FROM issues AS i JOIN prepared AS p ON p.n = i.n AND p.skip IS NULL WHERE i.col = ? AND i.kind IN ('
                . Database::placeholders(count(self::ISSUE_KINDS_EMPTIED)) . ') ORDER BY i.n',
                [$plan->column, ...self::ISSUE_KINDS_EMPTIED],
            ) as $row) {
                $rows[(int) $row['n']] = true;
            }
            foreach (array_keys($linkIssues[$plan->column] ?? []) as $n) {
                $rows[$n] = true;
            }
            if ($rows === []) {
                continue;
            }
            ksort($rows);
            $field = $schema->field($keys[$plan->key]);
            $columns[] = [
                'name' => $field->name,
                'field' => $field->key,
                'emptied' => count($rows),
                'rows' => array_slice(array_keys($rows), 0, IoLimits::REPORT_ROWS),
            ];
            $emptiedTotal += count($rows);
        }
        $links = [];
        foreach ($plans as $plan) {
            if ($plan->isLink() && $plan->target !== null && (($linksAdded[$plan->key] ?? 0) > 0 || ($duplicates[$plan->key] ?? 0) > 0)) {
                $entry = ['field' => $keys[$plan->key], 'table' => $plan->target->tableKey, 'added' => $linksAdded[$plan->key] ?? 0];
                if (($duplicates[$plan->key] ?? 0) > 0) {
                    $entry['duplicate_titles'] = $duplicates[$plan->key];
                }
                $links[] = $entry;
            }
        }
        $report = [];
        if ($columns !== []) {
            $report['columns'] = $columns;
        }
        if ($skipped['empty'] > 0 || $skipped['over_limit'] > 0) {
            $report['skipped'] = $skipped;
        }
        if ($links !== []) {
            $report['links'] = $links;
        }
        if ($requiredEmpty !== []) {
            $report['required_empty'] = $requiredEmpty;
        }

        return new ImportReport(
            $job->key(),
            Timestamp::format($clock->now()),
            $userKey,
            ImportReport::SOURCE_FILE,
            $job->meta('plugin'),
            (string) $job->meta('file_name'),
            $job->metaInt('file_size'),
            (string) $job->meta('file_sha256'),
            $tableKey,
            $newTable,
            $job->metaInt('read_rows'),
            $imported,
            $skipped['empty'] + $skipped['over_limit'],
            $emptiedTotal,
            array_sum($linksAdded),
            $snapshotFile,
            $report,
        );
    }

    /**
     * Required fields of an existing target left empty, counted over the
     * prepared rows that will be imported (before the transaction).
     *
     * @return list<array{field: string, count: int}>
     */
    private function requiredEmpty(ImportJob $job, Mapping $mapping, Schema $schema): array
    {
        $mapped = [];
        foreach ($mapping->included() as $column) {
            if ($column->fieldKey !== null) {
                $mapped[$column->fieldKey] = true;
            }
        }
        $counts = [];
        $required = [];
        foreach ($schema->fields((string) $mapping->tableKey) as $field) {
            if ($field->required && in_array($field->type, [...Inference::IMPORTABLE, 'relation'], true) && $field->type !== 'checkbox') {
                $required[] = $field->key;
                $counts[$field->key] = 0;
            }
        }
        if ($required === []) {
            return [];
        }
        foreach ($this->preparedRows($job) as $record) {
            foreach ($required as $key) {
                $value = isset($mapped[$key]) ? ($record[$key] ?? null) : null;
                $empty = $value === null || (is_array($value) && ($value['titles'] ?? []) === []);
                if ($empty) {
                    $counts[$key]++;
                }
            }
        }
        $result = [];
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $result[] = ['field' => $key, 'count' => $count];
            }
        }

        return $result;
    }

    /**
     * Prepared rows that will be imported, in `n` order, read in pages.
     *
     * @return \Generator<int, array<string, mixed>> row number => record
     */
    private function preparedRows(ImportJob $job): \Generator
    {
        $after = 0;
        do {
            $rows = $job->db()->all('SELECT n, record FROM prepared WHERE skip IS NULL AND n > ? ORDER BY n LIMIT ?', [$after, self::PAGE]);
            foreach ($rows as $row) {
                $after = (int) $row['n'];
                $record = json_decode((string) $row['record'], true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($record)) {
                    throw new \UnexpectedValueException('A prepared record is not an object.');
                }
                yield $after => $record;
            }
        } while (count($rows) === self::PAGE);
    }

    /** The reverse field name in the target table: the new table's name, made unique there (" 2", " 3"). */
    private static function reverseName(Database $db, FieldTypes $types, string $targetKey, string $name): string
    {
        $used = [];
        foreach ((new Schema($db, $types))->fields($targetKey) as $field) {
            $used[strtolower($field->name)] = true;
        }
        $candidate = $name;
        for ($n = 2; isset($used[strtolower($candidate)]); $n++) {
            $suffix = ' ' . $n;
            $candidate = Text::trim(ColumnStats::cut($name, SchemaEditor::MAX_FIELD_NAME - strlen($suffix))) . $suffix;
        }

        return $candidate;
    }

    /** @param list<ColumnPlan> $plans */
    private static function planOf(array $plans, int $column): ColumnPlan
    {
        foreach ($plans as $plan) {
            if ($plan->column === $column) {
                return $plan;
            }
        }

        throw new \LogicException('No plan for an included column.');
    }

    private static function isTargetChanged(ValidationFailed $failed): bool
    {
        foreach ($failed->problems as $problem) {
            if ($problem->key === 'problem.import.target_changed') {
                return true;
            }
        }

        return false;
    }

    private function finish(ImportJob $job, ImportReport $report): ImportReport
    {
        $job->db()->write(function () use ($job, $report): void {
            $job->setMeta('report', $report->key);
            $job->moveTo(JobState::Done);
        });

        return $report;
    }
}
