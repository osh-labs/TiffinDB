<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\Archive\ArchiveManifest;
use TiffinDB\Core\Io\Archive\ArchiveWriter;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\ExportTable;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\ExportSourceBuilder;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\ProjectRecord;

/**
 * Exports and the project archive download (M3 spec sections 10 and 13.2).
 *
 * Authorization as ImportOperations: `io.use` on the project through
 * Context::project, then Context::requireCurrentActor. Each export is written into its
 * own `exports` job folder, whose owner file names the Admin and the
 * project; finish() removes only a folder owned by the actor for that
 * project, and ignores anything else without saying whether it exists.
 */
final class ExportOperations
{
    public const EXPORTS = 'exports';
    public const SCOPE_TABLE = 'table';
    public const SCOPE_PROJECT = 'project';
    public const ARCHIVE_MEDIA_TYPE = 'application/zip';
    public const ARCHIVE_SUFFIX = '.tiffindb.zip';
    private const KIND = 'export';
    /** The order formats are offered in; others follow by name. */
    private const ORDER = ['export/csv', 'export/xlsx', 'export/json'];
    private const FALLBACK_BASE = 'export';
    /** The project's attachment folder (AttachmentStore::DIR; this package does not use the store). */
    private const ATTACHMENTS = 'attachments';

    public function __construct(private readonly Context $c)
    {
    }

    public function screen(Actor $actor, string $projectKey): ExportScreen
    {
        $project = $this->authorise($actor, $projectKey);
        $db = $this->c->opener->open($project);
        $schema = new Schema($db, $this->c->types);
        $records = new Records($db, $schema, $this->c->types, $this->c->clock);
        $tables = [];
        foreach ($schema->tables() as $table) {
            $tables[] = new TableSummary($table, count($schema->fields($table->key)), $records->count($table->key));
        }
        $formats = [];
        foreach ($this->enabledExporters() as $plugin) {
            $manifest = $plugin->manifest;
            if ($manifest === null) {
                continue;
            }
            try {
                $exporter = $this->exporter($plugin);
                $settings = [];
                foreach ([self::SCOPE_TABLE, self::SCOPE_PROJECT] as $scope) {
                    $settings[$scope] = in_array($scope, $manifest->scopes, true)
                        ? IoSupport::plugin($plugin, static fn (): Settings => $exporter->settings($scope))->items
                        : [];
                }
            } catch (PluginFailure) {
                // Logged by IoSupport::plugin; a plugin that cannot even describe its options is not offered.
                continue;
            }
            $formats[] = new ExportFormat($plugin->ref(), $manifest->name, $manifest->extensions[0] ?? '', $manifest->scopes, $settings[self::SCOPE_TABLE], $settings[self::SCOPE_PROJECT]);
        }

        return new ExportScreen($project, $tables, $formats, true, $this->projectBytes($project->key));
    }

    /**
     * Writes an export with an enabled export plugin: one table when
     * $tableKey is given (scope `table`), else every table (scope
     * `project`). Audits `export.created` (format, scope, record count).
     *
     * @param array<mixed> $settings setting name => value; unknown names and values are ignored
     * @throws ValidationFailed `problem.options.invalid` at `format`, `scope`, or `table`; `problem.export.failed`
     */
    public function export(Actor $actor, string $projectKey, string $formatRef, ?string $tableKey, array $settings): ExportFile
    {
        $project = $this->authorise($actor, $projectKey);
        $plugin = null;
        foreach ($this->enabledExporters() as $candidate) {
            if ($candidate->ref() === $formatRef) {
                $plugin = $candidate;
            }
        }
        $manifest = $plugin?->manifest;
        if ($plugin === null || $manifest === null || $manifest->mediaType === null || count($manifest->extensions) !== 1) {
            throw new ValidationFailed([new Problem('format', 'problem.options.invalid')]);
        }
        $scope = $tableKey === null ? self::SCOPE_PROJECT : self::SCOPE_TABLE;
        if (!in_array($scope, $manifest->scopes, true)) {
            throw new ValidationFailed([new Problem('scope', 'problem.options.invalid')]);
        }
        $db = $this->c->opener->open($project);
        $schema = new Schema($db, $this->c->types);
        $tableName = null;
        if ($tableKey !== null) {
            foreach ($schema->tables() as $table) {
                if ($table->key === $tableKey) {
                    $tableName = $table->name;
                }
            }
            if ($tableName === null) {
                throw new ValidationFailed([new Problem('table', 'problem.options.invalid')]);
            }
        }
        $values = [];
        foreach ($settings as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }
        $extension = $manifest->extensions[0];

        $this->c->staging()->sweep();
        [$jobKey, $folder] = $this->c->staging()->create(self::EXPORTS);
        $target = $folder . '/export.' . $extension;
        try {
            IoSupport::writeOwner($folder, ['user' => $actor->userId, 'project' => $project->key]);
            $exporter = $this->exporter($plugin);
            $chosen = IoSupport::plugin($plugin, static fn (): Settings => $exporter->settings($scope))->with($values);
            $source = ExportSourceBuilder::build($db, $schema, $this->c->types, $tableKey, $this->c->clock, $this->c->appVersion);
            $records = array_sum(array_map(static fn (ExportTable $t): int => $t->recordCount, $source->tables()));
            IoSupport::plugin($plugin, static function () use ($exporter, $source, $chosen, $target): void {
                $exporter->write($source, $chosen, $target);
            });
            if (!Fs::isFile($target)) {
                throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin wrote no file.');
            }
            $size = Fs::size($target);
        } catch (\Throwable $error) {
            IoSupport::removeJob($this->c, self::EXPORTS, $jobKey);
            if (!$error instanceof PluginFailure) {
                error_log('TiffinDB: an export failed (' . $error::class . ').');
            }

            throw new ValidationFailed([new Problem('format', 'problem.export.failed')]);
        }
        $this->c->audit->record(
            AuditAction::EXPORT_CREATED,
            $actor->userId,
            $project->id,
            $tableKey === null ? 'project' : 'table',
            $tableKey ?? $project->key,
            ['format' => $plugin->ref(), 'scope' => $scope, 'records' => $records],
        );
        $base = IoSupport::fileBase($tableName ?? $project->name, self::FALLBACK_BASE);

        return new ExportFile($jobKey, $target, $base . '.' . $extension, $manifest->mediaType, $size);
    }

    /**
     * The project archive (section 11.1): a consistent copy of the project
     * file, its manifest, and its attachment files. Audits `archive.exported`.
     *
     * @throws ValidationFailed `problem.export.failed`
     */
    public function archive(Actor $actor, string $projectKey): ExportFile
    {
        $project = $this->authorise($actor, $projectKey);
        $db = $this->c->opener->open($project);
        $this->c->staging()->sweep();
        [$jobKey, $folder] = $this->c->staging()->create(self::EXPORTS);
        $target = $folder . '/archive.zip';
        try {
            IoSupport::writeOwner($folder, ['user' => $actor->userId, 'project' => $project->key]);
            $manifest = (new ArchiveWriter())->write(
                $db,
                $this->c->opener->folder($project->key),
                $target,
                ArchiveManifest::facts(Timestamp::format($this->c->clock->now()), $this->c->appVersion, $project->name),
            );
            $size = Fs::size($target);
        } catch (\Throwable $error) {
            IoSupport::removeJob($this->c, self::EXPORTS, $jobKey);
            error_log('TiffinDB: a project archive could not be written (' . $error::class . ').');

            throw new ValidationFailed([new Problem(null, 'problem.export.failed')]);
        }
        $this->c->audit->record(AuditAction::ARCHIVE_EXPORTED, $actor->userId, $project->id, 'project', $project->key, [
            'attachments' => $manifest->attachments,
        ]);

        return new ExportFile($jobKey, $target, IoSupport::fileBase($project->name, 'project') . self::ARCHIVE_SUFFIX, self::ARCHIVE_MEDIA_TYPE, $size);
    }

    /**
     * Removes an export job folder once the download was sent. A key that
     * is not a key, an absent job, and a job of another person or project
     * are ignored.
     */
    public function finish(Actor $actor, string $projectKey, string $jobKey): void
    {
        $project = $this->authorise($actor, $projectKey);
        if (!Keys::isValid($jobKey)) {
            return;
        }
        $folder = $this->c->staging()->folder(self::EXPORTS, $jobKey);
        if ($folder === null || !IoSupport::ownedBy($folder, $actor->userId, $project->key)) {
            return;
        }
        IoSupport::removeJob($this->c, self::EXPORTS, $jobKey);
    }

    /**
     * About the size of the project archive (M4 CF-5): the project file
     * plus the regular files in the folders of `attachments/` whose names
     * are two lower-case hex digits. Symbolic links are neither followed
     * nor counted; a part that cannot be read counts as 0.
     */
    private function projectBytes(string $projectKey): int
    {
        $bytes = 0;
        try {
            $bytes += Fs::size($this->c->opener->path($projectKey));
        } catch (\RuntimeException) {
            // Counted as 0: the page still shows the archive panel.
        }
        $root = $this->c->opener->folder($projectKey) . '/' . self::ATTACHMENTS;
        if (!Fs::isDir($root)) {
            return $bytes;
        }
        try {
            foreach (Fs::entries($root) as $prefix) {
                $folder = $root . '/' . $prefix;
                if (preg_match('/\A[0-9a-f]{2}\z/', $prefix) !== 1 || !Fs::isDir($folder)) {
                    continue;
                }
                foreach (Fs::entries($folder) as $name) {
                    if (Fs::isFile($folder . '/' . $name)) {
                        $bytes += Fs::size($folder . '/' . $name);
                    }
                }
            }
        } catch (\RuntimeException) {
            // Whatever was counted before the unreadable part stands.
        }

        return $bytes;
    }

    private function authorise(Actor $actor, string $projectKey): ProjectRecord
    {
        $project = $this->c->project($actor, Permission::ImportExport, $projectKey);
        $this->c->requireCurrentActor($actor);

        return $project;
    }

    /** @return list<PluginInfo> enabled export plugins: CSV, Excel workbook, JSON, then others by name */
    private function enabledExporters(): array
    {
        $plugins = array_values(array_filter(
            $this->c->plugins(),
            static fn (PluginInfo $p): bool => $p->kind === self::KIND && $p->status === PluginStatus::Enabled && $p->manifest !== null,
        ));
        usort($plugins, static function (PluginInfo $a, PluginInfo $b): int {
            $rankA = array_search($a->ref(), self::ORDER, true);
            $rankB = array_search($b->ref(), self::ORDER, true);
            $rankA = $rankA === false ? count(self::ORDER) : $rankA;
            $rankB = $rankB === false ? count(self::ORDER) : $rankB;

            return [$rankA, $a->manifest?->name ?? '', $a->id] <=> [$rankB, $b->manifest?->name ?? '', $b->id];
        });

        return $plugins;
    }

    private function exporter(PluginInfo $plugin): Exporter
    {
        return IoSupport::plugin($plugin, fn (): Exporter => $this->c->pluginLoader()->exporter($plugin));
    }
}
