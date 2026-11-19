<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ExportFile;
use TiffinDB\Core\App\ExportFormat;
use TiffinDB\Core\App\ExportOperations;
use TiffinDB\Core\App\ExportScreen;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use Throwable;

/**
 * The export page and its two downloads (docs/design/m3/export.md; M3
 * spec part 2 sections 2.2 and 3.1).
 *
 * Every decision about who may export is the core's: ExportOperations
 * refuses a Member (AccessDenied, the 403 page) and a project the actor
 * cannot see (NotFound, 404), and checks the format, scope, and table.
 * The controller only reads the form and turns the ExportFile into a
 * download: the media type and file name come from the core (the
 * plugin's manifest and the core's file-name rules), never from the
 * request, and Response::download() builds the headers (Content-
 * Disposition with an ASCII fallback and `filename*`, no-store; the
 * kernel adds nosniff). The file is sent straight away from the POST
 * that made it and removed afterwards through ExportOperations::finish,
 * so there is no download URL to guess, share, or reload.
 */
final class ExportController
{
    /** Core field names => element ids of the controls the problems point to. */
    private const FIELDS = ['format' => 'f-format', 'scope' => 'f-scope', 'table' => 'f-table'];

    /** The scopes a request may name. */
    private const SCOPES = ['table', 'project'];

    /** The CSV and Excel workbook plugins: with both on, the page points to the workbook for spreadsheets (P135). */
    private const CSV = 'export/csv';
    private const XLSX = 'export/xlsx';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params GET /projects/{project}/export */
    public function form(Request $request, array $params): Response
    {
        $screen = $this->exports()->screen($this->context->actor(), $params['project'] ?? '');

        return $this->page($screen, null, [], 200);
    }

    /** @param array<string, string> $params POST /projects/{project}/export */
    public function export(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $screen = $this->exports()->screen($actor, $params['project'] ?? '');
        $values = self::posted($request);

        $format = null;
        foreach ($screen->formats as $candidate) {
            if ($candidate->ref === $values['format']) {
                $format = $candidate;
            }
        }
        if (!in_array($values['scope'], self::SCOPES, true)) {
            return $this->page($screen, $values, [new Problem('scope', 'problem.options.invalid')], 422);
        }
        // A format offering one scope ignores the other choice (export.md section 2).
        $scope = $values['scope'];
        if ($format !== null && !in_array($scope, $format->scopes, true) && count($format->scopes) === 1) {
            $scope = $format->scopes[0];
        }
        $tableKey = $scope === 'table' ? $values['table'] : null;
        $settings = $format === null ? [] : self::chosenSettings($format, $scope, $values['settings'][$format->ref] ?? []);

        try {
            $file = $this->exports()->export($actor, $screen->project->key, $values['format'], $tableKey, $settings);
        } catch (ValidationFailed $failed) {
            return $this->page($screen, $values, $failed->problems, 422);
        }

        return $this->download($screen->project->key, $file);
    }

    /** @param array<string, string> $params POST /projects/{project}/export/archive */
    public function archive(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $screen = $this->exports()->screen($actor, $params['project'] ?? '');
        try {
            $file = $this->exports()->archive($actor, $screen->project->key);
        } catch (ValidationFailed $failed) {
            return $this->page($screen, null, $failed->problems, 422);
        }

        return $this->download($screen->project->key, $file);
    }

    /**
     * The download of a file the core has just written, removed by the
     * core once it has been sent (or once sending has failed).
     */
    private function download(string $projectKey, ExportFile $file): Response
    {
        $actor = $this->context->actor();
        $services = $this->context->services();
        $finish = static function () use ($services, $actor, $projectKey, $file): void {
            try {
                $services->operations()->exports()->finish($actor, $projectKey, $file->jobKey);
            } catch (Throwable $error) {
                error_log('TiffinDB: an export file could not be removed after sending (' . $error::class . ').');
            } finally {
                try {
                    $services->close();
                } catch (Throwable) {
                    // Nothing more to tidy; the request is over.
                }
            }
        };
        try {
            $response = Response::download($file->path, $file->mediaType, $file->fileName, true);
        } catch (Throwable $error) {
            $finish();

            throw $error;
        }

        return $response->withAfterSend($finish);
    }

    /**
     * The posted form, every value a string (anything else is empty).
     *
     * @return array{format: string, scope: string, table: string, settings: array<string, array<string, string>>}
     */
    private static function posted(Request $request): array
    {
        $settings = [];
        $raw = $request->post['setting'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $ref => $values) {
                if (!is_string($ref) || !is_array($values)) {
                    continue;
                }
                foreach ($values as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $settings[$ref][$name] = $value;
                    }
                }
            }
        }

        return [
            'format' => $request->input('format'),
            'scope' => $request->input('scope'),
            'table' => $request->input('table'),
            'settings' => $settings,
        ];
    }

    /**
     * The chosen format's settings for the scope: each boolean is '1' when
     * its box was ticked and '0' otherwise (an unticked box sends
     * nothing); each choice is the posted value, which the core checks
     * against the setting's choices. Settings of other formats are never
     * passed on.
     *
     * @param array<string, string> $posted
     * @return array<string, string>
     */
    private static function chosenSettings(ExportFormat $format, string $scope, array $posted): array
    {
        $values = [];
        foreach ($scope === 'table' ? $format->tableSettings : $format->projectSettings as $setting) {
            if ($setting->kind === 'boolean') {
                $values[$setting->name] = ($posted[$setting->name] ?? '') === '1' ? '1' : '0';
            } elseif (isset($posted[$setting->name])) {
                $values[$setting->name] = $posted[$setting->name];
            }
        }

        return $values;
    }

    /**
     * @param array{format: string, scope: string, table: string, settings: array<string, array<string, string>>}|null $values posted values, or null for the defaults
     * @param list<Problem> $problems
     */
    private function page(ExportScreen $screen, ?array $values, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $format = $view->format();
        [$list, $errors] = ProjectController::problems($this->context, $problems, self::FIELDS);
        $project = $screen->project;

        $tables = [];
        foreach ($screen->tables as $summary) {
            $tables[] = [
                'key' => $summary->table->key,
                'label' => $view->text('export.scope.table_option', ['table' => $summary->table->name, 'count' => $summary->recordCount]),
            ];
        }

        $chosenFormat = $values['format'] ?? ($screen->formats[0]->ref ?? '');
        $refs = [];
        $formats = [];
        foreach ($screen->formats as $index => $choice) {
            $refs[] = $choice->ref;
            $formats[] = [
                'ref' => $choice->ref,
                'name' => $choice->name,
                'scopeKey' => self::scopeKey($choice->scopes),
                'checked' => $choice->ref === $chosenFormat,
                'settings' => self::settings($choice, $index, $values['settings'][$choice->ref] ?? null),
            ];
        }
        $hasSettings = false;
        foreach ($formats as $choice) {
            $hasSettings = $hasSettings || $choice['settings'] !== [];
        }

        $scope = $values['scope'] ?? ($tables === [] ? 'project' : 'table');
        $table = $values['table'] ?? ($tables[0]['key'] ?? '');

        return $this->context->page('export/form', [
            'title' => $view->text('export.title'),
            'layout' => 'io',
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key . '/tables'],
                ['label' => $view->text('export.crumb'), 'path' => null],
            ],
            'problems' => $list,
            'errors' => $errors,
            'projectKey' => $project->key,
            'formats' => $formats,
            'spreadsheetHint' => in_array(self::CSV, $refs, true) && in_array(self::XLSX, $refs, true),
            'hasSettings' => $hasSettings,
            'tables' => $tables,
            'scope' => $scope === 'project' ? 'project' : 'table',
            'table' => $table,
        ], $status, 'projects');
    }

    /** @param list<string> $scopes */
    private static function scopeKey(array $scopes): string
    {
        $table = in_array('table', $scopes, true);
        $project = in_array('project', $scopes, true);

        return match (true) {
            $table && $project => 'export.format.scope_both',
            $project => 'export.format.scope_project',
            default => 'export.format.scope_table',
        };
    }

    /**
     * A format's settings for the page: those of the table scope, then
     * those of the project scope with other names, each with its input
     * name, its plain-text label and help, and the value to show (posted,
     * else the plugin's default).
     *
     * @param array<string, string>|null $posted the posted values of this format, or null for the defaults
     * @return list<array{input: string, label: string, help: string, kind: string, value: string, choices: list<array{value: string, label: string}>, index: int, format: int}>
     */
    private static function settings(ExportFormat $format, int $formatIndex, ?array $posted): array
    {
        $out = [];
        $seen = [];
        foreach ([...$format->tableSettings, ...$format->projectSettings] as $setting) {
            if (isset($seen[$setting->name])) {
                continue;
            }
            $seen[$setting->name] = true;
            $value = $setting->value;
            if ($posted !== null) {
                $value = $setting->kind === 'boolean'
                    ? (($posted[$setting->name] ?? '') === '1' ? '1' : '0')
                    : ($posted[$setting->name] ?? $setting->value);
            }
            $choices = [];
            foreach ($setting->choices as $choice) {
                $choices[] = ['value' => $choice->value, 'label' => $choice->label];
            }
            $out[] = [
                'input' => 'setting[' . $format->ref . '][' . $setting->name . ']',
                'label' => $setting->label,
                'help' => $setting->help,
                'kind' => $setting->kind === 'boolean' ? 'boolean' : 'choice',
                'value' => $value,
                'choices' => $choices,
                'index' => count($out),
                'format' => $formatIndex,
            ];
        }

        return $out;
    }

    private function exports(): ExportOperations
    {
        return $this->context->services()->operations()->exports();
    }
}
