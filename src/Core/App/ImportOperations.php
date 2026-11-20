<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\ColumnMapping;
use TiffinDB\Core\Io\ColumnStats;
use TiffinDB\Core\Io\Committer;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\Inspection;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;
use TiffinDB\Core\Io\Csv\CsvFile;
use TiffinDB\Core\Io\ImportJob;
use TiffinDB\Core\Io\ImportLog;
use TiffinDB\Core\Io\Inference;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Io\JobState;
use TiffinDB\Core\Io\LinkTarget;
use TiffinDB\Core\Io\Mapping;
use TiffinDB\Core\Io\MappingCheck;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Io\Preparer;
use TiffinDB\Core\Io\PreviewResult;
use TiffinDB\Core\Io\Reader;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Io\TypeGuess;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Importing a file into a table (M3 spec sections 6 and 13.1).
 *
 * Authorization: every method first checks `io.use` on the project
 * through Context::project (an ungranted Member, an unknown key, and a
 * project that is not active are NotFound; a granted Member AccessDenied),
 * then that the actor is still the active person, with the role, the session
 * names (Context::requireCurrentActor).
 * Job keys that are not keys, absent jobs, and jobs of another project or
 * another person are NotFound (P134); the owner is checked before the job
 * lock is tried, so another Admin cannot even learn that the job is busy.
 *
 * A method that needs a job in a state it is not in throws NotFound,
 * except readChunk and prepareChunk, which report the step as finished so
 * that a repeated Continue lands on the job's current screen.
 */
final class ImportOperations
{
    /** Reason code of a job whose plugin is no longer enabled (section 4.3). */
    public const FAILURE_PLUGIN = 'plugin-unavailable';
    /** Reason code of a job whose commit failed and was rolled back (section 6.6). */
    public const FAILURE_COMMIT = 'commit-failed';
    public const IMPORTS = 'imports';
    private const KIND = 'import';
    private const MAX_INPUT_NAME = 60;

    public function __construct(private readonly Context $c)
    {
    }

    public function startScreen(Actor $actor, string $projectKey): ImportStart
    {
        $project = $this->authorise($actor, $projectKey);
        $formats = [];
        foreach ($this->enabledImporters() as $plugin) {
            $manifest = $plugin->manifest;
            if ($manifest !== null) {
                $formats[] = new PluginChoice($plugin->ref(), $manifest->name, $manifest->extensions);
            }
        }
        [$db, $schema] = $this->project($project);

        return new ImportStart($project, $formats, IoLimits::MAX_UPLOAD_BYTES, self::tables($db, $schema, $this->c));
    }

    /**
     * Step 1 (section 6.1): moves the upload into a new job, detects and
     * inspects it with the chosen plugin (else the enabled one for the
     * file's extension), and reads the first chunk. Returns the job key.
     *
     * @throws ValidationFailed `problem.upload.*` or `problem.import.*` at `file`; nothing is kept
     */
    public function start(Actor $actor, string $projectKey, string $uploadPath, string $clientName, ?string $pluginRef): string
    {
        $project = $this->authorise($actor, $projectKey);
        $clientName = IoSupport::clientName($clientName);
        IoSupport::checkUpload($this->c, $uploadPath);
        $extension = IoSupport::extension($clientName);
        $plugin = $this->choosePlugin($pluginRef, $extension);
        $name = $plugin->manifest?->name ?? $plugin->id;

        $this->c->sweepStaging($project, $this->c->opener->open($project));
        [$key, $folder] = $this->c->staging()->create(self::IMPORTS);
        $job = null;
        try {
            Fs::moveIn($uploadPath, $folder . '/' . ImportJob::UPLOAD);
            $size = Fs::size($folder . '/' . ImportJob::UPLOAD);
            $file = new SourceFile($folder . '/' . ImportJob::UPLOAD, $extension, $size, $folder . '/' . StagingArea::TEMP);
            $importer = $this->importer($plugin);
            $settings = IoSupport::plugin($plugin, static fn (): Settings => $importer->detect($file));
            $inspection = IoSupport::plugin($plugin, static fn (): Inspection => $importer->inspect($file, $settings));
            self::checkInspection($inspection, $name);
            $tableIndex = self::firstTable($inspection);
            $job = ImportJob::create($folder, [
                'project' => $project->key,
                'user' => (string) $actor->userId,
                'plugin' => $plugin->ref(),
                'file_name' => $clientName,
                'file_size' => (string) $size,
                'file_sha256' => IoSupport::sha256($folder . '/' . ImportJob::UPLOAD),
                'extension' => $extension,
                'settings' => self::json($settings->values()),
                'inspection' => self::json(self::inspectionToArray($inspection)),
                'table_index' => (string) $tableIndex,
                'created_at' => Timestamp::format($this->c->clock->now()),
            ]);
            $job->lock();
            // The first chunk runs here; a file the plugin cannot read is refused at step 1.
            $this->reader()->chunk($job, $importer, $settings, $inspection->tables[$tableIndex]);
        } catch (\Throwable $error) {
            $job?->close();
            IoSupport::removeJob($this->c, self::IMPORTS, $key);
            if ($error instanceof PluginFailure) {
                throw new ValidationFailed([self::readProblem($error, $name)]);
            }

            throw $error;
        }
        $job->close();

        return $key;
    }

    public function job(Actor $actor, string $projectKey, string $jobKey): ImportJobView
    {
        return $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job): ImportJobView {
            $inspection = self::inspection($job);
            $table = $inspection->tables[self::tableIndex($job, $inspection)];

            return new ImportJobView(
                $project,
                $job->key(),
                $job->state()->value,
                (string) $job->meta('file_name'),
                $job->metaInt('read_rows'),
                $table->rowCount,
                $job->meta('read_done') === '1',
                $job->rowCount(),
                $job->metaInt('prepared_rows'),
                $job->failure(),
            );
        });
    }

    /** Reads one chunk (section 6.2). A job that is not reading reports the step as finished. */
    public function readChunk(Actor $actor, string $projectKey, string $jobKey): ImportProgress
    {
        return $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job): ImportProgress {
            $inspection = self::inspection($job);
            $table = $inspection->tables[self::tableIndex($job, $inspection)];
            if ($job->state() === JobState::Reading) {
                $plugin = $this->jobPlugin($job);
                if ($plugin !== null) {
                    try {
                        $importer = $this->importer($plugin);
                        $settings = $this->jobSettings($job, $plugin, $importer);
                        $this->reader()->chunk($job, $importer, $settings, $table);
                    } catch (PluginFailure) {
                        // The reader has moved the job to Failed; the job page shows why.
                        if ($job->state() !== JobState::Failed) {
                            $job->fail('plugin-' . PluginFailure::REASON_FAILED);
                        }
                    }
                }
            }

            return new ImportProgress($job->metaInt('read_rows'), $table->rowCount, $job->state() !== JobState::Reading);
        });
    }

    public function readingOptions(Actor $actor, string $projectKey, string $jobKey): ReadingScreen
    {
        return $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job): ReadingScreen {
            self::requireState($job, JobState::Reading, JobState::Review, JobState::Preparing, JobState::Ready);
            $plugin = $this->jobPlugin($job);
            if ($plugin === null) {
                throw new NotFound('The import job has failed.');
            }
            $inspection = self::inspection($job);
            try {
                $settings = $this->jobSettings($job, $plugin, $this->importer($plugin));
            } catch (PluginFailure) {
                $job->fail('plugin-' . PluginFailure::REASON_FAILED);

                throw new NotFound('The import job has failed.');
            }

            return new ReadingScreen(
                $project,
                $job->key(),
                (string) $job->meta('file_name'),
                $settings->items,
                $inspection->tables,
                self::tableIndex($job, $inspection),
                $inspection->readAs,
            );
        });
    }

    /**
     * Applies new reading settings (unknown names and values a setting
     * does not allow are ignored) and, optionally, another source table;
     * the file is inspected again and the job goes back to Reading with
     * its rows, statistics, and mapping emptied.
     *
     * @param array<mixed> $values setting name => value
     * @throws ValidationFailed `problem.import.*` when the file cannot be read that way; the job is unchanged
     */
    public function setReadingOptions(Actor $actor, string $projectKey, string $jobKey, array $values, ?int $tableIndex = null): void
    {
        $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job) use ($values, $tableIndex): void {
            self::requireState($job, JobState::Reading, JobState::Review, JobState::Preparing, JobState::Ready);
            $plugin = $this->jobPlugin($job);
            if ($plugin === null) {
                throw new NotFound('The import job has failed.');
            }
            $name = $plugin->manifest?->name ?? $plugin->id;
            $strings = [];
            foreach ($values as $setting => $value) {
                if (is_string($setting) && is_string($value)) {
                    $strings[$setting] = $value;
                }
            }
            try {
                $importer = $this->importer($plugin);
                $file = $job->sourceFile();
                $settings = IoSupport::plugin($plugin, static fn (): Settings => $importer->detect($file))->with($strings);
                $inspection = IoSupport::plugin($plugin, static fn (): Inspection => $importer->inspect($file, $settings));
            } catch (PluginFailure $failure) {
                throw new ValidationFailed([self::readProblem($failure, $name)]);
            }
            self::checkInspection($inspection, $name);
            $index = $tableIndex;
            if ($index === null || $index < 0 || $index >= count($inspection->tables)) {
                $current = $job->metaInt('table_index');
                $index = $tableIndex === null && $current < count($inspection->tables) ? $current : self::firstTable($inspection);
            }
            $job->resetReading();
            $job->db()->write(static function () use ($job, $settings, $inspection, $index): void {
                $job->setMeta('settings', self::json($settings->values()));
                $job->setMeta('inspection', self::json(self::inspectionToArray($inspection)));
                $job->setMeta('table_index', (string) $index);
            });
        });
    }

    /**
     * The review step (section 6.4, P120). $input, when given, re-shows
     * posted values (after a refused saveMapping); $problems are shown as given.
     *
     * @param array<mixed>|null $input as saveMapping
     * @param list<Problem> $problems
     */
    public function review(Actor $actor, string $projectKey, string $jobKey, ?array $input, array $problems): ImportReview
    {
        return $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job) use ($input, $problems): ImportReview {
            self::requireState($job, JobState::Review);
            [$db, $schema] = $this->project($project);
            $inspection = self::inspection($job);
            $table = $inspection->tables[self::tableIndex($job, $inspection)];
            $stats = $job->stats();
            $guesses = $this->guesses($table, $stats, $schema);
            $mapping = $this->currentMapping($job, $schema, $table, $guesses);
            if ($input !== null) {
                try {
                    $mapping = $this->parse($job, $schema, $table, $guesses, $input, false);
                } catch (ValidationFailed|NotFound) {
                    // Tampered input: show the stored mapping.
                }
            }

            $columns = [];
            $eligible = $mapping->target === Mapping::EXISTING ? MappingCheck::eligibleFields($schema, (string) $mapping->tableKey) : [];
            $fieldChoices = [];
            foreach ($eligible as $field) {
                $relation = $field->type === 'relation' ? $schema->relationOf($field) : null;
                $fieldChoices[] = new FieldChoice(
                    $field->key,
                    $field->name,
                    $field->type,
                    $relation === null ? null : $schema->table($relation->otherTable($field->key))->name,
                );
            }
            foreach ($mapping->columns as $column) {
                $guess = $guesses[$column->column];
                $typeChoices = [];
                foreach ($guess->typeChoices as $choice) {
                    $target = MappingCheck::linkTarget($choice, $schema);
                    $typeChoices[] = new TypeChoice($choice, $target?->name);
                }
                $columns[] = new ReviewColumn(
                    $column->column,
                    $table->columns[$column->column],
                    $column->include,
                    $column->fieldName ?? $guess->fieldName,
                    $column->type ?? $guess->type,
                    $typeChoices,
                    $column->fieldKey,
                    $fieldChoices,
                    ($stats[$column->column] ?? new ColumnStats())->firstValues,
                    !$guess->include,
                );
            }

            $rows = $job->rowCount();
            $skipped = $job->skipped();
            $replaced = $job->metaInt('replaced');
            $warnings = [];
            foreach ($job->db()->all("SELECT col, COUNT(*) AS c FROM issues WHERE kind = 'too-long' GROUP BY col ORDER BY col") as $issue) {
                $warnings[] = new ReviewWarning('too_long', ['column' => $table->columns[(int) $issue['col']] ?? '', 'count' => (int) $issue['c']]);
            }
            $importCount = $rows;
            $partial = false;
            if (MappingCheck::check($mapping, $schema, $this->c->types, $stats) === []) {
                $preview = (new Preparer($this->c->types))->preview($job, $mapping, $schema, $db);
                $partial = $preview->partial;
                $importCount = $rows - ($preview->rows - $preview->importCount);
                foreach ($this->previewWarnings($preview, $mapping, $schema, $table) as $warning) {
                    $warnings[] = $warning;
                }
            }
            $extra = (int) $job->db()->value("SELECT COUNT(*) FROM issues WHERE kind = 'extra-cells'");
            if ($extra > 0) {
                $warnings[] = new ReviewWarning('extra_cells', ['count' => $extra]);
            }
            if ($replaced > 0) {
                $warnings[] = new ReviewWarning('replaced', ['count' => $replaced]);
            }
            if ($skipped['over_limit'] > 0) {
                $warnings[] = new ReviewWarning('over_limit', ['max' => IoLimits::MAX_ROWS]);
            }
            if ($partial) {
                $warnings[] = new ReviewWarning('preview_partial', ['count' => IoLimits::PREVIEW_ROWS]);
            }
            $settings = self::storedSettings($job);

            return new ImportReview(
                $project,
                $job->key(),
                (string) $job->meta('file_name'),
                $inspection->readAs,
                $rows,
                ($settings['heading'] ?? '1') !== '0',
                count($table->columns),
                $skipped['over_limit'] > 0,
                $replaced,
                $mapping->target,
                $mapping->target === Mapping::NEW ? $mapping->newTableName : null,
                $mapping->tableKey,
                self::tables($db, $schema, $this->c),
                $columns,
                $warnings,
                array_values($problems),
                max(0, $importCount),
                $partial,
            );
        });
    }

    /**
     * "Check again" (P137): stores the posted target and the column values
     * that pass the shape checks, with no MappingCheck; the job stays in
     * Review. A target that differs from the stored one starts from that
     * target's default mapping. An existing-table key that is not a table
     * of the project is NotFound.
     *
     * @param array<mixed> $input as saveMapping
     */
    public function recheck(Actor $actor, string $projectKey, string $jobKey, array $input): void
    {
        $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job) use ($input): void {
            self::requireState($job, JobState::Review);
            [, $schema] = $this->project($project);
            $inspection = self::inspection($job);
            $table = $inspection->tables[self::tableIndex($job, $inspection)];
            $mapping = $this->parse($job, $schema, $table, $this->guesses($table, $job->stats(), $schema), $input, true);
            $job->db()->write(static function () use ($job, $mapping): void {
                $job->setMetaJson('mapping', $mapping->toArray());
            });
        });
    }

    /**
     * Accepts the mapping (section 6.4): every MappingCheck problem at once,
     * else the job moves to Preparing with the target's hash stored.
     *
     * Input: `target` (`new` or `existing`), `table_name`, `table` (a table
     * key), and per source column n: `include[n]` (`1` to bring it in),
     * `name[n]` and `type[n]` (new table), `field[n]` (existing table; ''
     * leaves the column out).
     *
     * @param array<mixed> $input
     * @throws ValidationFailed
     */
    public function saveMapping(Actor $actor, string $projectKey, string $jobKey, array $input): void
    {
        $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job) use ($input): void {
            self::requireState($job, JobState::Review);
            [$db, $schema] = $this->project($project);
            $inspection = self::inspection($job);
            $table = $inspection->tables[self::tableIndex($job, $inspection)];
            $mapping = $this->parse($job, $schema, $table, $this->guesses($table, $job->stats(), $schema), $input, false);
            $problems = MappingCheck::check($mapping, $schema, $this->c->types, $job->stats());
            if ($problems !== []) {
                throw new ValidationFailed($problems);
            }
            $job->acceptMapping($mapping, Committer::schemaHash($db, $mapping));
        });
    }

    /**
     * Prepares one chunk (section 6.5). A target that changed since the
     * mapping was accepted sends the job back to Review with
     * `problem.import.target_changed`; a job that is not preparing reports
     * the step as finished.
     */
    public function prepareChunk(Actor $actor, string $projectKey, string $jobKey): ImportProgress
    {
        return $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job): ImportProgress {
            if ($job->state() === JobState::Preparing && $this->jobPlugin($job) !== null) {
                [$db, $schema] = $this->project($project);
                $mapping = $job->mapping();
                if ($mapping === null || !hash_equals((string) $job->meta('schema_hash'), Committer::schemaHash($db, $mapping))) {
                    $job->db()->write(static function () use ($job): void {
                        $job->moveTo(JobState::Review);
                    });

                    throw new ValidationFailed([new Problem('table', 'problem.import.target_changed')]);
                }
                (new Preparer($this->c->types))->chunk($job, $schema);
            }

            return new ImportProgress($job->metaInt('prepared_rows'), $job->rowCount(), $job->state() !== JobState::Preparing);
        });
    }

    /**
     * Saves the rows (section 6.6) after an `import` snapshot, recorded as
     * `snapshot.taken`; audits `import.completed` and removes the job.
     *
     * A failure inside the committing step (a database error, or anything
     * else the committer did not expect) is rolled back by the committer's
     * single transaction, so the project is unchanged; the job is Failed
     * with reason `commit-failed`, the cause goes to the server log only,
     * and the result carries `problem.import.commit_failed` instead of a
     * log key (the web layer answers with a 303 to the job address, which
     * shows the failure page).
     *
     * @throws ValidationFailed `problem.import.target_changed` or `problem.import.too_many_links` (the job is back in Review)
     * @throws \TiffinDB\Core\Error\Busy the project is busy (the job stays Ready)
     */
    public function save(Actor $actor, string $projectKey, string $jobKey): ImportCommitResult
    {
        return $this->saveJob($actor, $projectKey, $jobKey, false);
    }

    /**
     * As save(), returning the log key; a failed commit (the job is then
     * Failed with `commit-failed`, the project unchanged) passes its error
     * on, as before save() existed. The web layer uses save().
     */
    public function commit(Actor $actor, string $projectKey, string $jobKey): string
    {
        return (string) $this->saveJob($actor, $projectKey, $jobKey, true)->logKey;
    }

    private function saveJob(Actor $actor, string $projectKey, string $jobKey, bool $rethrow): ImportCommitResult
    {
        [$logKey, $report, $project] = $this->withJob($actor, $projectKey, $jobKey, function (ProjectRecord $project, ImportJob $job) use ($actor, $rethrow): array {
            if ($job->state() === JobState::Done) {
                // An earlier commit finished but its clean-up did not.
                return [(string) $job->meta('report'), null, $project];
            }
            self::requireState($job, JobState::Ready);
            $mapping = $job->mapping();
            if ($mapping === null) {
                throw new \LogicException('A ready job has a mapping.');
            }
            [$db] = $this->project($project);
            // Test seam (composition root only, never request data): Context::seam is called with
            // `import.commit.<step>` inside the transaction after each sub-step of section 6.6 step 3.
            $committer = new Committer(function (string $step): void {
                $this->c->seam('import.commit.' . $step);
            });
            try {
                $report = $committer->commit(
                    $job,
                    $mapping,
                    $db,
                    $this->c->types,
                    $this->c->clock,
                    $this->c->editor($project, $db, $actor),
                    $this->c->snapshots,
                    $this->c->opener->folder($project->key),
                    $actor->userKey,
                    function (SnapshotInfo $info) use ($project, $actor): void {
                        $this->c->recordSnapshot($project, $actor, $info);
                    },
                );
            } catch (ValidationFailed|Busy $expected) {
                throw $expected;
            } catch (\Throwable $error) {
                error_log('TiffinDB: an import commit failed and was rolled back (' . $error::class . ').');
                if ($job->state() !== JobState::Failed) {
                    $job->fail(self::FAILURE_COMMIT);
                }
                if ($rethrow) {
                    throw $error;
                }

                return [null, null, $project];
            }

            return [$report->key, $report, $project];
        });
        if ($logKey === null) {
            return new ImportCommitResult(null, new Problem(null, 'problem.import.commit_failed'));
        }
        if ($report !== null) {
            $this->c->audit->record(AuditAction::IMPORT_COMPLETED, $actor->userId, $project->id, 'table', $report->tableKey, [
                'plugin' => $report->plugin,
                'rows' => $report->rowsImported,
                'new_table' => $report->newTable,
            ]);
        }
        IoSupport::removeJob($this->c, self::IMPORTS, $jobKey);

        return new ImportCommitResult($logKey);
    }

    /** The report of an import, from the project's import log (format section 4.7). */
    public function report(Actor $actor, string $projectKey, string $logKey): ImportReportView
    {
        $project = $this->authorise($actor, $projectKey);
        if (!Keys::isValid($logKey)) {
            throw new NotFound('No such import.');
        }
        [$db, $schema] = $this->project($project);
        $report = ImportLog::get($db, $logKey);
        if ($report === null) {
            throw new NotFound('No such import.');
        }
        $tables = [];
        foreach ($schema->tables() as $table) {
            $tables[$table->key] = $table;
        }
        $fieldName = static function (mixed $key) use ($schema, $tables): ?string {
            if (!is_string($key)) {
                return null;
            }
            foreach ($tables as $table) {
                foreach ($schema->fields($table->key) as $field) {
                    if ($field->key === $key) {
                        return $field->name;
                    }
                }
            }

            return null;
        };
        $data = $report->report;
        $columns = [];
        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
            if (!is_array($column) || !is_string($column['field'] ?? null)) {
                continue;
            }
            $rows = array_values(array_filter(is_array($column['rows'] ?? null) ? $column['rows'] : [], static fn (mixed $n): bool => is_int($n)));
            $columns[] = new ReportColumn(
                $column['field'],
                $fieldName($column['field']) ?? (is_string($column['name'] ?? null) ? $column['name'] : ''),
                is_int($column['emptied'] ?? null) ? $column['emptied'] : 0,
                $rows,
            );
        }
        $links = [];
        foreach (is_array($data['links'] ?? null) ? $data['links'] : [] as $link) {
            if (!is_array($link) || !is_string($link['field'] ?? null) || !is_string($link['table'] ?? null)) {
                continue;
            }
            $links[] = new ReportLink($link['field'], $fieldName($link['field']), $link['table'], ($tables[$link['table']] ?? null)?->name, is_int($link['added'] ?? null) ? $link['added'] : 0);
        }
        $required = [];
        foreach (is_array($data['required_empty'] ?? null) ? $data['required_empty'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['field'] ?? null)) {
                continue;
            }
            $required[] = new ReportRequired($entry['field'], $fieldName($entry['field']), is_int($entry['count'] ?? null) ? $entry['count'] : 0);
        }

        return new ImportReportView($project, $report->tableKey === null ? null : ($tables[$report->tableKey] ?? null), $report, $columns, $links, $required);
    }

    /** Removes the job and its folder ("Start again"); any state. */
    public function cancel(Actor $actor, string $projectKey, string $jobKey): void
    {
        $this->withJob($actor, $projectKey, $jobKey, static function (): void {
        });
        IoSupport::removeJob($this->c, self::IMPORTS, $jobKey);
    }

    // ------------------------------------------------------------------ internals

    /** The permission check of every method: `io.use` on the project, then the current person (Context::requireCurrentActor). */
    private function authorise(Actor $actor, string $projectKey): ProjectRecord
    {
        $project = $this->c->project($actor, Permission::ImportExport, $projectKey);
        $this->c->requireCurrentActor($actor);

        return $project;
    }

    /**
     * Runs $work on the actor's own job of this project while holding its
     * lock; the job file is closed afterwards.
     *
     * @template T
     * @param \Closure(ProjectRecord, ImportJob): T $work
     * @return T
     */
    private function withJob(Actor $actor, string $projectKey, string $jobKey, \Closure $work): mixed
    {
        $project = $this->authorise($actor, $projectKey);
        $folder = $this->c->staging()->folder(self::IMPORTS, $jobKey);
        if ($folder === null) {
            throw new NotFound('No such import job.');
        }
        $job = ImportJob::open($folder);
        try {
            $job->checkOwner($project->key, $actor->userId);
            $job->lock();

            return $work($project, $job);
        } finally {
            $job->close();
        }
    }

    /** @return array{0: Database, 1: Schema} */
    private function project(ProjectRecord $project): array
    {
        $db = $this->c->opener->open($project);

        return [$db, new Schema($db, $this->c->types)];
    }

    /** @return list<TableSummary> */
    private static function tables(Database $db, Schema $schema, Context $c): array
    {
        $records = new Records($db, $schema, $c->types, $c->clock);
        $list = [];
        foreach ($schema->tables() as $table) {
            $list[] = new TableSummary($table, count($schema->fields($table->key)), $records->count($table->key));
        }

        return $list;
    }

    /** @return list<PluginInfo> enabled import plugins */
    private function enabledImporters(): array
    {
        return array_values(array_filter(
            $this->c->plugins(),
            static fn (PluginInfo $p): bool => $p->kind === self::KIND && $p->status === PluginStatus::Enabled && $p->manifest !== null,
        ));
    }

    /** The chosen enabled import plugin, else the first enabled one whose extensions include $extension. */
    private function choosePlugin(?string $ref, string $extension): PluginInfo
    {
        foreach ($this->enabledImporters() as $plugin) {
            if ($ref !== null ? $plugin->ref() === $ref : ($extension !== '' && in_array($extension, $plugin->manifest?->extensions ?? [], true))) {
                return $plugin;
            }
        }

        throw new ValidationFailed([new Problem('file', 'problem.import.format_unknown')]);
    }

    /**
     * The job's plugin when it is still an enabled import plugin (scanned
     * again, section 4.3); otherwise the job moves to Failed with
     * `plugin-unavailable` and null is returned.
     */
    private function jobPlugin(ImportJob $job): ?PluginInfo
    {
        $ref = (string) $job->meta('plugin');
        foreach ($this->enabledImporters() as $plugin) {
            if ($plugin->ref() === $ref) {
                return $plugin;
            }
        }
        if ($job->state() !== JobState::Failed && $job->state() !== JobState::Done) {
            $job->fail(self::FAILURE_PLUGIN);
        }

        return null;
    }

    private function importer(PluginInfo $plugin): Importer
    {
        return IoSupport::plugin($plugin, fn (): Importer => $this->c->pluginLoader()->importer($plugin));
    }

    private function jobSettings(ImportJob $job, PluginInfo $plugin, Importer $importer): Settings
    {
        $file = $job->sourceFile();

        return IoSupport::plugin($plugin, static fn (): Settings => $importer->detect($file))->with(self::storedSettings($job));
    }

    private function reader(): Reader
    {
        return new Reader($this->c->types);
    }

    /** @return array<string, string> */
    private static function storedSettings(ImportJob $job): array
    {
        $values = [];
        foreach ($job->metaJson('settings') ?? [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<ColumnStats> $stats
     * @return list<TypeGuess>
     */
    private function guesses(SourceTable $table, array $stats, Schema $schema): array
    {
        return Inference::guessAll($table->columns, $stats, LinkTarget::all($schema));
    }

    /**
     * The stored (draft or accepted) mapping when it still fits the job's
     * columns and target, else the default new-table mapping.
     *
     * @param list<TypeGuess> $guesses
     */
    private function currentMapping(ImportJob $job, Schema $schema, SourceTable $table, array $guesses): Mapping
    {
        $stored = null;
        try {
            $stored = $job->mapping();
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            $stored = null;
        }
        if ($stored !== null && count($stored->columns) === count($table->columns)
            && ($stored->target === Mapping::NEW || self::hasTable($schema, (string) $stored->tableKey))) {
            return $stored;
        }

        return MappingCheck::defaults(Mapping::NEW, $schema, $guesses, $this->defaultName($job, $table));
    }

    private function defaultName(ImportJob $job, SourceTable $table): string
    {
        return MappingCheck::defaultTableName((string) $job->meta('file_name'), $table->name === '' ? null : $table->name);
    }

    /**
     * A mapping from posted input on top of the stored or default one. With
     * $shapeOnly (recheck), values that fail a shape check are dropped and a
     * missing or unknown target keeps the stored one; otherwise they are kept
     * as given for MappingCheck to report, and an unknown target is a problem.
     * An existing-table key that is not a table of the project is NotFound.
     *
     * @param list<TypeGuess> $guesses
     * @param array<mixed> $input
     */
    private function parse(ImportJob $job, Schema $schema, SourceTable $table, array $guesses, array $input, bool $shapeOnly): Mapping
    {
        $current = $this->currentMapping($job, $schema, $table, $guesses);
        $target = $input['target'] ?? null;
        if ($target !== Mapping::NEW && $target !== Mapping::EXISTING) {
            if (!$shapeOnly) {
                throw new ValidationFailed([new Problem('target', 'problem.options.invalid')]);
            }
            $target = $current->target;
        }
        $tableKey = null;
        if ($target === Mapping::EXISTING) {
            $tableKey = $input['table'] ?? ($shapeOnly ? $current->tableKey : null);
            if (!is_string($tableKey) || !self::hasTable($schema, $tableKey)) {
                throw new NotFound('No such table.');
            }
        }
        $sameTarget = $current->target === $target && $current->tableKey === $tableKey;
        $base = $sameTarget ? $current : MappingCheck::defaults($target, $schema, $guesses, $this->defaultName($job, $table), $tableKey);
        if (!$sameTarget && $shapeOnly) {
            // P137: the columns posted belong to the other target; start from this target's defaults.
            // X-12: a posted name that is empty or absent keeps the stored one, which an
            // existing-table mapping carries while the job is in Review.
            $newName = self::keptName($input['table_name'] ?? null, $current->newTableName);
            if ($newName === null) {
                return $base;
            }

            return new Mapping($target, $newName, $base->tableKey, $base->columns);
        }

        $newName = $base->newTableName;
        $posted = $input['table_name'] ?? null;
        if ($shapeOnly) {
            $newName = self::keptName($posted, $newName);
        } elseif ($target === Mapping::NEW && is_string($posted)) {
            $newName = $posted;
        }
        $include = is_array($input['include'] ?? null) ? $input['include'] : [];
        $names = is_array($input['name'] ?? null) ? $input['name'] : [];
        $types = is_array($input['type'] ?? null) ? $input['type'] : [];
        $fields = is_array($input['field'] ?? null) ? $input['field'] : [];
        $eligible = $target === Mapping::EXISTING ? MappingCheck::eligibleFields($schema, (string) $tableKey) : [];
        $columns = [];
        foreach ($base->columns as $column) {
            $n = $column->column;
            $in = ($include[$n] ?? null) === '1';
            if ($target === Mapping::NEW) {
                $name = $column->fieldName;
                $postedName = $names[$n] ?? null;
                if (is_string($postedName)) {
                    $name = $shapeOnly ? (self::shortText($postedName) ?? $name) : $postedName;
                }
                $type = $column->type;
                $postedType = $types[$n] ?? null;
                if (is_string($postedType) && (!$shapeOnly || self::isKnownType($postedType, $schema))) {
                    $type = $postedType;
                }
                $columns[] = new ColumnMapping($n, $in, $name, $type, null);

                continue;
            }
            $fieldKey = $column->fieldKey;
            $postedField = $fields[$n] ?? null;
            if ($postedField === '') {
                $fieldKey = null;
            } elseif (is_string($postedField) && (!$shapeOnly || isset($eligible[$postedField]))) {
                $fieldKey = $postedField;
            }
            $columns[] = new ColumnMapping($n, $in, null, null, $fieldKey);
        }

        return new Mapping($target, $target === Mapping::NEW || $shapeOnly ? $newName : null, $tableKey, $columns);
    }

    /**
     * The new-table name a recheck keeps (X-12): the posted one when it
     * passes the shape check and is not blank, else the stored one.
     */
    private static function keptName(mixed $posted, ?string $stored): ?string
    {
        $name = self::shortText($posted);

        return $name !== null && Text::trim($name) !== '' ? $name : self::shortText($stored);
    }

    /** A posted name that passes the shape check: a UTF-8 string of at most 60 characters. */
    private static function shortText(mixed $value): ?string
    {
        return is_string($value) && Text::isUtf8($value) && Text::length($value) <= self::MAX_INPUT_NAME ? $value : null;
    }

    private static function isKnownType(string $type, Schema $schema): bool
    {
        return in_array($type, Inference::IMPORTABLE, true) || MappingCheck::linkTarget($type, $schema) !== null;
    }

    private static function hasTable(Schema $schema, string $tableKey): bool
    {
        foreach ($schema->tables() as $table) {
            if ($table->key === $tableKey) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ReviewWarning> */
    private function previewWarnings(PreviewResult $preview, Mapping $mapping, Schema $schema, SourceTable $table): array
    {
        $warnings = [];
        $byColumn = [];
        foreach ($mapping->columns as $column) {
            $byColumn[$column->column] = $column;
        }
        foreach ($preview->emptied as $n => $count) {
            $column = $byColumn[$n] ?? null;
            $heading = $table->columns[$n] ?? '';
            if ($mapping->target === Mapping::EXISTING && $column?->fieldKey !== null) {
                $field = $schema->field($column->fieldKey);
                $warnings[] = $field->type === 'choice'
                    ? new ReviewWarning('not_a_choice', ['column' => $heading, 'count' => $count, 'field' => $field->name])
                    : new ReviewWarning('unreadable', ['column' => $heading, 'count' => $count, 'type' => $field->type]);
            } else {
                $warnings[] = new ReviewWarning('unreadable', ['column' => $heading, 'count' => $count, 'type' => (string) $column?->type]);
            }
        }
        foreach ([[$preview->linksAdded, 'links_added'], [$preview->linksRefused, 'link_unreadable']] as [$counts, $kind]) {
            foreach ($counts as $n => $count) {
                $target = $this->linkTargetOf($byColumn[$n] ?? null, $mapping, $schema);
                $params = ['column' => $table->columns[$n] ?? '', 'table' => $target?->name ?? '', 'count' => $count];
                if ($kind === 'links_added') {
                    // The design's {items} is the linked table's name in lower case ("new rooms"), not its item name.
                    $params['items'] = strtolower($target?->name ?? '');
                }
                $warnings[] = new ReviewWarning($kind, $params);
            }
        }

        return $warnings;
    }

    private function linkTargetOf(?ColumnMapping $column, Mapping $mapping, Schema $schema): ?LinkTarget
    {
        if ($column === null) {
            return null;
        }
        if ($mapping->target === Mapping::NEW) {
            return MappingCheck::linkTarget((string) $column->type, $schema);
        }
        $field = $column->fieldKey === null ? null : $schema->field($column->fieldKey);
        $relation = $field === null ? null : $schema->relationOf($field);

        return $relation === null || $field === null ? null : LinkTarget::of($schema, $relation->otherTable($field->key));
    }

    private static function requireState(ImportJob $job, JobState ...$states): void
    {
        if (!in_array($job->state(), $states, true)) {
            throw new NotFound('The import job is not at that step.');
        }
    }

    private static function checkInspection(Inspection $inspection, string $pluginName): void
    {
        if ($inspection->tables === []) {
            throw new ValidationFailed([new Problem('file', 'problem.import.unreadable', ['format' => $pluginName])]);
        }
        foreach ($inspection->tables as $table) {
            if (count($table->columns) > IoLimits::MAX_COLUMNS) {
                throw new ValidationFailed([new Problem('file', 'problem.import.too_many_columns', ['max' => IoLimits::MAX_COLUMNS])]);
            }
        }
    }

    /** The first table that is not hidden, else the first. */
    private static function firstTable(Inspection $inspection): int
    {
        foreach ($inspection->tables as $index => $table) {
            if (!$table->hidden) {
                return (int) $index;
            }
        }

        return 0;
    }

    private static function tableIndex(ImportJob $job, Inspection $inspection): int
    {
        $index = $job->metaInt('table_index');
        if ($index < 0 || $index >= count($inspection->tables)) {
            throw new \UnexpectedValueException('The job names a source table the file does not have.');
        }

        return $index;
    }

    /** @return array<string, mixed> */
    private static function inspectionToArray(Inspection $inspection): array
    {
        $tables = [];
        foreach ($inspection->tables as $table) {
            $tables[] = ['name' => $table->name, 'columns' => $table->columns, 'row_count' => $table->rowCount, 'hidden' => $table->hidden];
        }

        return ['read_as' => $inspection->readAs, 'tables' => $tables];
    }

    private static function inspection(ImportJob $job): Inspection
    {
        $data = $job->metaJson('inspection') ?? [];
        $tables = [];
        foreach (is_array($data['tables'] ?? null) ? $data['tables'] : [] as $table) {
            if (!is_array($table) || !is_string($table['name'] ?? null) || !is_array($table['columns'] ?? null)) {
                throw new \UnexpectedValueException('The job holds a damaged inspection.');
            }
            $rowCount = $table['row_count'] ?? null;
            $tables[] = new SourceTable($table['name'], array_values($table['columns']), is_int($rowCount) ? $rowCount : null, ($table['hidden'] ?? false) === true);
        }
        if ($tables === []) {
            throw new \UnexpectedValueException('The job holds no source table.');
        }

        return new Inspection($tables, is_string($data['read_as'] ?? null) ? $data['read_as'] : '');
    }

    private static function readProblem(PluginFailure $failure, string $pluginName): Problem
    {
        if ($failure->reason === PluginFailure::REASON_TOO_LARGE && $failure->getMessage() === CsvFile::TOO_MANY_COLUMNS) {
            // CF-3: the column limit has its own message; an over-long record stays `unsupported`.
            return new Problem('file', 'problem.import.too_many_columns', ['max' => IoLimits::MAX_COLUMNS]);
        }

        return match ($failure->reason) {
            PluginFailure::REASON_UNREADABLE => new Problem('file', 'problem.import.unreadable', ['format' => $pluginName]),
            PluginFailure::REASON_UNSUPPORTED, PluginFailure::REASON_TOO_LARGE => new Problem('file', 'problem.import.unsupported'),
            default => new Problem('file', 'problem.import.failed'),
        };
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
