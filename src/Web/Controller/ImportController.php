<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ImportJobView;
use TiffinDB\Core\App\ImportOperations;
use TiffinDB\Core\App\ImportReportView;
use TiffinDB\Core\App\ImportStart;
use TiffinDB\Core\App\ReadingScreen;
use TiffinDB\Core\App\ReviewWarning;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Ids;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\UploadedFile;

/**
 * Importing a file into a table (docs/design/m3/import.md; M3 spec part 2
 * sections 3 and 8). Every decision about who may import is the core's
 * (ImportOperations, permission `io.use`): a granted Member gets the 403
 * page from every route before any job is looked up, an ungranted Member
 * or an unknown project the 404 page; a job key that is not a key, a job
 * of another project or another Admin, a job removed by the clean-up, and
 * a job that is not at the step a request needs are all the same 404, so
 * nothing tells whether a job exists.
 *
 * Every POST answers 303 (post, redirect, get) except a refusal, which
 * re-renders its page with 422; reloading never repeats a step. The
 * Continue route runs one chunk for the job's current state and saves the
 * rows only when the job is ready AND the request carries `step=save`,
 * which only the "Save the rows" form sends: the auto-continue forms of
 * the reading and checking steps never carry it, and the page that shows
 * "Save the rows" does not load the auto-continue script. A second save
 * (a double press, the back button) finds the job gone and is sent to its
 * report; the core's own duplicate-commit check covers the race.
 *
 * File-derived text (file names, headings, sheet names, first values,
 * plugin text) reaches the templates as plain text and is escaped there;
 * nothing from the file is ever put in a URL.
 */
final class ImportController
{
    /** The import steps, in order (shared strings, steps partial). */
    private const STEPS = ['steps.import.choose', 'steps.import.check', 'steps.import.import', 'steps.import.report'];

    /** Warning kinds in the order of import.md section 4 item 3, with their string keys. */
    private const WARNINGS = [
        'too_long' => 'import.warning.too_long',
        'unreadable' => 'import.warning.unreadable',
        'not_a_choice' => 'import.warning.not_a_choice',
        'links_added' => 'import.warning.links_added',
        'link_unreadable' => 'import.warning.link_unreadable',
        'extra_cells' => 'import.warning.extra_cells',
        'replaced' => 'import.warning.replaced',
        'over_limit' => 'import.warning.over_limit',
        'preview_partial' => 'import.warning.partial',
    ];

    /** Type ids with a name of their own (import.type.<id>); others read "the field's type". */
    private const TYPES = [
        'text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration',
        'checkbox', 'choice', 'rating', 'url', 'email', 'phone',
    ];

    /** Failure reason codes of the core (ImportJobView::$failure) and the sentence that explains them. */
    private const FAILURES = [
        'commit-failed' => 'import.failed.commit',
        'plugin-unavailable' => 'import.failed.plugin',
    ];

    /** File-derived text longer than this many characters is cut on screen (headings can be 20,000 characters). */
    private const MAX_SHOWN = 200;

    /** How many row numbers the report lists per field (the core keeps 20). */
    private const REPORT_ROWS = 20;

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------ step 1

    /** GET /projects/{project}/import. */
    public function start(Request $request, array $params): Response
    {
        $start = $this->imports()->startScreen($this->context->actor(), $params['project'] ?? '');

        return $this->startPage($start, null, [], 200);
    }

    /**
     * POST /projects/{project}/import (multipart): the upload. A file PHP
     * did not accept is refused here with 422 before the core is called
     * with it; the core moves an accepted file into a job folder under a
     * name of its own and reads the first chunk.
     */
    public function upload(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $imports = $this->imports();
        // Authorises first: a Member never learns anything about uploads.
        $start = $imports->startScreen($actor, $params['project'] ?? '');
        $format = $request->input('format');
        $file = $request->file('file');
        $problem = $file === null ? 'problem.upload.missing' : $file->problem();
        if ($problem !== null || $file === null || $file->tmpPath === null) {
            return $this->startPage($start, $format, [new Problem('file', $problem ?? 'problem.upload.failed', ['max' => $start->maxBytes])], 422);
        }
        $ref = $format === '' || $format === 'auto' ? null : $format;
        try {
            $job = $imports->start($actor, $start->project->key, $file->tmpPath, $file->clientName, $ref);
        } catch (ValidationFailed $failed) {
            return $this->startPage($start, $format, $failed->problems, 422);
        }

        return $this->context->redirect($this->jobPath($start->project->key, $job));
    }

    // ------------------------------------------------------- the job page

    /**
     * GET /projects/{project}/import/{job}: the page for the job's state
     * (reading or checking progress, the review, the save step, or the
     * failure page). A job that has finished is sent to its report.
     */
    public function show(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $view = $this->job($projectKey, $jobKey);
        if ($view === null) {
            return $this->context->redirect($this->reportPath($projectKey, $jobKey));
        }

        return match ($view->state) {
            ImportJobView::STATE_REVIEW => $this->reviewPage($projectKey, $jobKey, null, [], 200),
            ImportJobView::STATE_FAILED => $this->failedPage($view),
            ImportJobView::STATE_DONE => $this->context->redirect($this->reportPath($projectKey, $jobKey)),
            default => $this->progressPage($view),
        };
    }

    /**
     * POST /projects/{project}/import/{job}: the review form. "Check
     * again" (action=recheck) stores the target and the column values that
     * pass the shape checks and draws the page again (P137); "Import"
     * accepts the mapping (422 with every problem) and starts checking the
     * rows. A job that is no longer in review is sent to its page.
     */
    public function saveMapping(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $view = $this->job($projectKey, $jobKey);
        if ($view === null) {
            return $this->context->redirect($this->reportPath($projectKey, $jobKey));
        }
        if ($view->state !== ImportJobView::STATE_REVIEW) {
            return $this->context->redirect($this->jobPath($projectKey, $jobKey));
        }
        $input = self::mappingInput($request);
        $actor = $this->context->actor();
        if ($request->input('action') === 'recheck') {
            // The existing-table page sends an empty name for the new table:
            // leave it out, so the stored or proposed name stays (X-12).
            if (trim((string) ($input['table_name'] ?? '')) === '') {
                unset($input['table_name']);
            }
            $this->imports()->recheck($actor, $projectKey, $jobKey, $input);

            return $this->context->redirect($this->jobPath($projectKey, $jobKey));
        }
        try {
            $this->imports()->saveMapping($actor, $projectKey, $jobKey, $input);
        } catch (ValidationFailed $failed) {
            return $this->reviewPage($projectKey, $jobKey, $input, $failed->problems, 422);
        }

        return $this->context->redirect($this->jobPath($projectKey, $jobKey));
    }

    /**
     * POST /projects/{project}/import/{job}/continue: one chunk of reading
     * or checking, or the save. Answers 303 to the job page (or to the
     * report after the save); a target that changed, or a save the core
     * refuses, shows the review again with 422 (the job is back in review,
     * so a reload only redirects).
     */
    public function continue(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $view = $this->job($projectKey, $jobKey);
        if ($view === null) {
            return $this->context->redirect($this->reportPath($projectKey, $jobKey));
        }
        $actor = $this->context->actor();
        $imports = $this->imports();
        try {
            if ($view->state === ImportJobView::STATE_READING) {
                $imports->readChunk($actor, $projectKey, $jobKey);
            } elseif ($view->state === ImportJobView::STATE_PREPARING) {
                $imports->prepareChunk($actor, $projectKey, $jobKey);
            } elseif ($view->state === ImportJobView::STATE_READY && $request->input('step') === 'save') {
                $result = $imports->save($actor, $projectKey, $jobKey);
                if ($result->failed() || $result->logKey === null) {
                    // Rolled back; the job is Failed (`commit-failed`) and its page is the failure page.
                    return $this->context->redirect($this->jobPath($projectKey, $jobKey));
                }
                $logKey = $result->logKey;
                $report = $imports->report($actor, $projectKey, $logKey);
                $this->context->flash('notice.import.done', [
                    'count' => $report->report->rowsImported,
                    'table' => $report->table === null ? $this->context->view()->text('import.report.table_gone') : $report->table->name,
                ]);

                return $this->context->redirect($this->reportPath($projectKey, $logKey));
            } elseif ($view->state === ImportJobView::STATE_DONE) {
                return $this->context->redirect($this->reportPath($projectKey, $jobKey));
            }
        } catch (ValidationFailed $failed) {
            return $this->reviewPage($projectKey, $jobKey, null, $failed->problems, 422);
        }

        return $this->context->redirect($this->jobPath($projectKey, $jobKey));
    }

    /** POST /projects/{project}/import/{job}/cancel: "Start again" removes the job. */
    public function cancel(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $this->imports()->cancel($this->context->actor(), $projectKey, $jobKey);

        return $this->context->redirect('/projects/' . $projectKey . '/import');
    }

    // ---------------------------------------------------- reading options

    /** GET /projects/{project}/import/{job}/reading. */
    public function readingForm(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $screen = $this->imports()->readingOptions($this->context->actor(), $projectKey, $jobKey);

        return $this->readingPage($screen, null, null, [], 200);
    }

    /**
     * POST /projects/{project}/import/{job}/reading: the file is read again
     * with the chosen settings (and sheet); the job goes back to reading.
     */
    public function reading(Request $request, array $params): Response
    {
        [$projectKey, $jobKey] = self::keys($params);
        $actor = $this->context->actor();
        $imports = $this->imports();
        $screen = $imports->readingOptions($actor, $projectKey, $jobKey);
        $posted = $request->post['setting'] ?? null;
        $posted = is_array($posted) ? $posted : [];
        $values = [];
        foreach ($screen->settings as $setting) {
            $value = $posted[$setting->name] ?? null;
            if ($setting->kind === 'boolean') {
                // An unticked box sends nothing.
                $values[$setting->name] = $value === '1' ? '1' : '0';
            } elseif (is_string($value)) {
                $values[$setting->name] = $value;
            }
        }
        $table = $request->input('table');
        $tableIndex = preg_match('/^[0-9]{1,3}\z/', $table) === 1 ? (int) $table : null;
        try {
            $imports->setReadingOptions($actor, $projectKey, $jobKey, $values, $tableIndex);
        } catch (ValidationFailed $failed) {
            return $this->readingPage($screen, $values, $tableIndex, $failed->problems, 422);
        }

        return $this->context->redirect($this->jobPath($projectKey, $jobKey));
    }

    // ------------------------------------------------------------ report

    /** GET /projects/{project}/import/{job}/report: from the project's import log, also after the job folder is gone. */
    public function report(Request $request, array $params): Response
    {
        [$projectKey, $logKey] = self::keys($params);
        $view = $this->imports()->report($this->context->actor(), $projectKey, $logKey);

        return $this->reportPage($view);
    }

    // ----------------------------------------------------------- pages

    /** @param list<Problem> $problems */
    private function startPage(ImportStart $start, ?string $format, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $project = $start->project;
        [$list] = $this->problems($problems, 'new');
        $extensions = [];
        $formats = [];
        $known = false;
        foreach ($start->formats as $choice) {
            foreach ($choice->extensions as $extension) {
                $extensions[] = $extension;
            }
            $dotted = UploadedFile::acceptList($choice->extensions);
            $known = $known || $format === $choice->ref;
            $formats[] = [
                'value' => $choice->ref,
                'name' => $choice->name,
                'extensions' => implode(', ', $dotted),
                'checked' => $format === $choice->ref,
            ];
        }
        $formatUnknown = false;
        foreach ($problems as $problem) {
            $formatUnknown = $formatUnknown || $problem->key === 'problem.import.format_unknown';
        }
        $fileProblems = [];
        foreach ($list as $item) {
            if ($item['field'] === Ids::make('f', 'file')) {
                $fileProblems[] = $item['text'];
            }
        }

        return $this->context->page('import/start', [
            'title' => $view->text('import.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($project),
            'problems' => $list,
            'steps' => self::steps(0),
            'action' => '/projects/' . $project->key . '/import',
            'cancel' => '/projects/' . $project->key,
            'accept' => UploadedFile::acceptList($extensions),
            'max' => $view->format()->fileSize($this->maxBytes($start->maxBytes)),
            'fileProblems' => $fileProblems,
            'showFormats' => count($formats) > 1 || ($formatUnknown && $formats !== []),
            'autoChecked' => !$known,
            'formats' => $formats,
        ], $status, 'projects');
    }

    /** Reading the file (step 1), checking the rows, or the save step (step 3). */
    private function progressPage(ImportJobView $job): Response
    {
        $view = $this->context->view();
        $format = $view->format();
        $base = $this->jobPath($job->project->key, $job->jobKey);
        $data = [
            'title' => $view->text('import.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($job->project),
            'action' => $base . '/continue',
            'cancel' => $base . '/cancel',
        ];
        if ($job->state === ImportJobView::STATE_READING) {
            $data += [
                'steps' => self::steps(0),
                'stage' => 'reading',
                'script' => 'import-continue',
                'progress' => [
                    'label' => 'import.reading.label',
                    'labelParams' => ['file' => self::shown($job->fileName)],
                    'done' => $job->readRows,
                    'total' => $job->totalRows,
                    'status' => 'import.reading.status',
                    'statusParams' => ['count' => $job->readRows],
                ],
            ];
        } elseif ($job->state === ImportJobView::STATE_PREPARING) {
            $data += [
                'steps' => self::steps(2),
                'stage' => 'preparing',
                'script' => 'import-continue',
                'progress' => [
                    'label' => 'import.preparing.label',
                    'done' => $job->preparedRows,
                    'total' => $job->storedRows,
                    'status' => 'import.preparing.status',
                    'statusParams' => ['done' => $format->number($job->preparedRows), 'total' => $job->storedRows],
                ],
            ];
        } else {
            // Ready: the save waits for a press; no auto-continue script on this page.
            $data += [
                'steps' => self::steps(2),
                'stage' => 'ready',
                'progress' => [
                    'label' => 'import.saving.label',
                    'done' => $job->storedRows,
                    'total' => $job->storedRows,
                    'status' => 'import.saving.status',
                ],
            ];
        }

        return $this->context->page('import/progress', $data, 200, 'projects');
    }

    /**
     * Step 2. $input re-shows posted values after a refusal.
     *
     * @param array<mixed>|null $input
     * @param list<Problem>     $problems
     */
    private function reviewPage(string $projectKey, string $jobKey, ?array $input, array $problems, int $status): Response
    {
        $review = $this->imports()->review($this->context->actor(), $projectKey, $jobKey, $input, $problems);
        $view = $this->context->view();
        $format = $view->format();
        $project = $review->project;
        [$list, $errors] = $this->problems($review->problems, $review->target);
        $existing = $review->target === 'existing';

        $tables = [];
        foreach ($review->tables as $summary) {
            $tables[] = ['value' => $summary->table->key, 'label' => $summary->table->name, 'selected' => $existing && $summary->table->key === $review->tableKey];
        }

        $columns = [];
        foreach ($review->columns as $column) {
            $n = $column->index;
            $heading = self::shown($column->heading);
            $types = [];
            foreach ($column->typeChoices as $choice) {
                $types[] = ['value' => $choice->value, 'label' => $this->typeName($choice->value, $choice->tableName), 'selected' => $choice->value === $column->type];
            }
            $fields = [];
            foreach ($column->fieldChoices as $choice) {
                $fields[] = [
                    'value' => $choice->key,
                    'label' => $view->text('import.review.field_choice', [
                        'name' => $choice->name,
                        'type' => $this->typeName($choice->type === 'relation' ? 'link:' : $choice->type, $choice->linkedTable),
                    ]),
                    'selected' => $choice->key === $column->fieldKey,
                ];
            }
            $nameId = Ids::make('f', 'name', $n);
            $typeId = Ids::make('f', 'type', $n);
            $fieldId = Ids::make('f', 'field', $n);
            $columns[] = [
                'n' => $n,
                'heading' => $heading,
                'include' => $column->include,
                'includeName' => 'include[' . $n . ']',
                'nameName' => 'name[' . $n . ']',
                'typeName' => 'type[' . $n . ']',
                'fieldName' => 'field[' . $n . ']',
                'name' => $column->fieldName,
                'types' => $types,
                'fields' => $fields,
                'noField' => $column->fieldKey === null,
                'first' => implode('; ', $column->firstValues),
                'leftOut' => $column->leftOutByDefault,
                'nameError' => $errors[$nameId] ?? null,
                'typeError' => $errors[$typeId] ?? null,
                'fieldError' => $errors[$fieldId] ?? null,
            ];
        }

        $warnings = [];
        foreach (array_keys(self::WARNINGS) as $kind) {
            foreach ($review->warnings as $warning) {
                if ($warning->kind === $kind) {
                    $warnings[] = ['key' => self::WARNINGS[$kind], 'params' => $this->warningParams($warning)];
                }
            }
        }

        $base = $this->jobPath($project->key, $jobKey);

        return $this->context->page('import/review', [
            'title' => $view->text('import.review.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($project),
            'problems' => $list,
            'steps' => self::steps(1),
            'facts' => [
                ['term' => 'import.review.file', 'value' => $review->fileName],
                ['term' => 'import.review.read_as', 'value' => $review->readAs],
                ['term' => 'import.review.rows', 'value' => $view->text($review->headingRow ? 'import.review.rows_heading' : 'import.review.rows_plain', ['count' => $review->rows])],
                ['term' => 'import.review.columns', 'value' => $format->number($review->columnCount)],
            ],
            'action' => $base,
            'reading' => $base . '/reading',
            'cancel' => $base . '/cancel',
            'existing' => $existing,
            'tableName' => $existing ? '' : (string) $review->newTableName,
            'tableNameError' => $errors[Ids::make('f', 'tablename')] ?? null,
            'tableError' => $errors[Ids::make('f', 'table')] ?? null,
            'tables' => $tables,
            'columns' => $columns,
            'warnings' => $warnings,
            'importCount' => $review->importCount,
        ], $status, 'projects');
    }

    /**
     * @param array<string, string>|null $values posted values to re-show
     * @param list<Problem>              $problems
     */
    private function readingPage(ReadingScreen $screen, ?array $values, ?int $tableIndex, array $problems, int $status): Response
    {
        $view = $this->context->view();
        [$list] = $this->problems($problems, 'new');
        $settings = [];
        foreach ($screen->settings as $i => $setting) {
            $value = $values[$setting->name] ?? $setting->value;
            $choices = [];
            foreach ($setting->choices as $choice) {
                $choices[] = ['value' => $choice->value, 'label' => $choice->label, 'checked' => $choice->value === $value];
            }
            $settings[] = [
                'n' => $i,
                'kind' => $setting->kind,
                'inputName' => 'setting[' . $setting->name . ']',
                'label' => $setting->label,
                'help' => $setting->help,
                'checked' => $value === '1',
                'choices' => $choices,
            ];
        }
        $current = $tableIndex ?? $screen->tableIndex;
        $sheets = [];
        if (count($screen->tables) > 1) {
            foreach ($screen->tables as $i => $table) {
                $name = self::shown($table->name);
                $sheets[] = [
                    'value' => (string) $i,
                    'label' => $table->hidden ? $view->text('import.reading_options.hidden_sheet', ['name' => $name]) : $name,
                    'checked' => $i === $current,
                ];
            }
        }
        $base = $this->jobPath($screen->project->key, $screen->jobKey);

        return $this->context->page('import/reading', [
            'title' => $view->text('import.reading_options.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($screen->project),
            'problems' => $list,
            'steps' => self::steps(0),
            'action' => $base . '/reading',
            'back' => $base,
            'settings' => $settings,
            'sheets' => $sheets,
        ], $status, 'projects');
    }

    private function failedPage(ImportJobView $job): Response
    {
        $view = $this->context->view();
        $reason = self::FAILURES[(string) $job->failure] ?? null;
        $problem = null;
        if ($reason === null && in_array($job->failure, ['plugin-unsupported', 'plugin-too-large'], true)) {
            $problem = 'problem.import.unsupported';
        }

        return $this->context->page('import/failed', [
            'title' => $view->text('import.failed.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($job->project),
            'reason' => $problem === null ? $view->text($reason ?? 'import.failed.read') : $view->format()->problemText($problem, []),
            'cancel' => $this->jobPath($job->project->key, $job->jobKey) . '/cancel',
        ], 200, 'projects');
    }

    private function reportPage(ImportReportView $view): Response
    {
        $text = $this->context->view();
        $format = $text->format();
        $project = $view->project;
        $report = $view->report;
        $data = $report->report;

        $skipped = is_array($data['skipped'] ?? null) ? $data['skipped'] : [];
        $skippedParts = [];
        if (is_int($skipped['empty'] ?? null) && $skipped['empty'] > 0) {
            $skippedParts[] = $text->text('import.report.skipped_empty', ['count' => $skipped['empty']]);
        }
        if (is_int($skipped['over_limit'] ?? null) && $skipped['over_limit'] > 0) {
            $skippedParts[] = $text->text('import.report.skipped_limit');
        }

        $columns = [];
        foreach ($view->columns as $column) {
            $rows = array_slice($column->rows, 0, self::REPORT_ROWS);
            $listed = implode(', ', array_map(static fn (int $n): string => (string) $n, $rows));
            $more = $column->count - count($rows);
            $columns[] = [
                'field' => $column->fieldName,
                'count' => $format->number($column->count),
                'rows' => $more > 0 && $rows !== [] ? $text->text('import.report.more_rows', ['rows' => $listed, 'count' => $format->number($more)]) : $listed,
            ];
        }
        $required = [];
        foreach ($view->requiredEmpty as $entry) {
            if ($entry->count > 0) {
                $required[] = ['count' => $entry->count, 'field' => (string) $entry->fieldName];
            }
        }
        $links = [];
        foreach ($view->links as $link) {
            if ($link->added > 0) {
                $links[] = ['count' => $link->added, 'table' => $link->tableName ?? $text->text('import.report.table_gone')];
            }
        }
        $table = $view->table;

        return $this->context->page('import/report', [
            'title' => $text->text('import.report.title'),
            'layout' => 'io',
            'breadcrumb' => $this->crumbs($project),
            'steps' => self::steps(3),
            'fileName' => $report->fileName,
            'table' => $table === null ? null : ['name' => $table->name, 'path' => '/projects/' . $project->key . '/tables/' . $table->key],
            'newTable' => $report->newTable,
            'imported' => $format->number($report->rowsImported),
            'skipped' => $format->number($report->rowsSkipped),
            'skippedParts' => $skippedParts,
            'emptied' => $format->number($report->valuesEmptied),
            'linksAdded' => $report->linksAdded > 0 ? $format->number($report->linksAdded) : null,
            'snapshot' => $report->snapshot,
            'columns' => $columns,
            'required' => $required,
            'links' => $links,
            'another' => '/projects/' . $project->key . '/import',
        ], 200, 'projects');
    }

    // ---------------------------------------------------------- helpers

    private function imports(): ImportOperations
    {
        return $this->context->services()->operations()->imports();
    }

    /**
     * The job's view, or null when the job is gone but the project's import
     * log holds its report (an earlier save finished: a reload or second
     * press goes to the report, nothing is saved twice). Otherwise the
     * core's NotFound stands.
     */
    private function job(string $projectKey, string $jobKey): ?ImportJobView
    {
        $actor = $this->context->actor();
        try {
            return $this->imports()->job($actor, $projectKey, $jobKey);
        } catch (NotFound $gone) {
            try {
                $this->imports()->report($actor, $projectKey, $jobKey);
            } catch (NotFound) {
                throw $gone;
            }

            return null;
        }
    }

    /** @return array{0: string, 1: string} project key, job key */
    private static function keys(array $params): array
    {
        return [(string) ($params['project'] ?? ''), (string) ($params['job'] ?? '')];
    }

    private function jobPath(string $projectKey, string $jobKey): string
    {
        return '/projects/' . $projectKey . '/import/' . $jobKey;
    }

    private function reportPath(string $projectKey, string $jobKey): string
    {
        return $this->jobPath($projectKey, $jobKey) . '/report';
    }

    /**
     * @param object $project the core's project record (key, name)
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(object $project): array
    {
        return [
            ['label' => (string) $project->name, 'path' => '/projects/' . (string) $project->key],
            ['label' => $this->context->view()->text('import.crumb'), 'path' => null],
        ];
    }

    /** @return list<array{label: string, state: string}> */
    private static function steps(int $now): array
    {
        $steps = [];
        foreach (self::STEPS as $i => $label) {
            $steps[] = ['label' => $label, 'state' => $i < $now ? 'done' : ($i === $now ? 'now' : 'next')];
        }

        return $steps;
    }

    /** The largest upload the page states: the core's cap lowered to PHP's limits. */
    private function maxBytes(int $coreMaxBytes): int
    {
        return $this->context->services()->uploadLimits()->effective($coreMaxBytes);
    }

    /**
     * The posted review form as ImportOperations reads it: only the named
     * fields, each a string or a map of strings by column number.
     *
     * @return array<string, mixed>
     */
    private static function mappingInput(Request $request): array
    {
        $input = [];
        foreach (['target', 'table_name', 'table'] as $name) {
            if (is_string($request->post[$name] ?? null)) {
                $input[$name] = $request->post[$name];
            }
        }
        foreach (['include', 'name', 'type', 'field'] as $name) {
            $posted = $request->post[$name] ?? null;
            if (!is_array($posted)) {
                continue;
            }
            $map = [];
            foreach ($posted as $n => $value) {
                if (is_int($n) && $n >= 0 && is_string($value)) {
                    $map[$n] = $value;
                }
            }
            $input[$name] = $map;
        }

        return $input;
    }

    /**
     * Problems for the error summary and each control's error text: the
     * core field (`file`, `table`, `target`, `col-<n>`) mapped to the
     * element that shows it. Name problems of a column go to its name
     * input, other column problems to its type menu (new table) or its
     * field menu (existing table).
     *
     * @param list<Problem> $problems
     * @return array{0: list<array{field: string|null, text: string}>, 1: array<string, string>}
     */
    private function problems(array $problems, string $target): array
    {
        $format = $this->context->view()->format();
        $list = [];
        $errors = [];
        foreach ($problems as $problem) {
            $params = $problem->params;
            if ($problem->key === 'problem.upload.too_large') {
                $params['max'] = $format->fileSize($this->maxBytes(is_int($params['max'] ?? null) && $params['max'] > 0 ? $params['max'] : PHP_INT_MAX));
            }
            $text = $format->problemText($problem->key, $params);
            $id = $this->problemElement($problem, $target);
            $list[] = ['field' => $id, 'text' => $text];
            if ($id !== null && !isset($errors[$id])) {
                $errors[$id] = $text;
            }
        }

        return [$list, $errors];
    }

    private function problemElement(Problem $problem, string $target): ?string
    {
        $field = $problem->field;
        if ($field === 'file') {
            return Ids::make('f', 'file');
        }
        if ($field === 'table') {
            return $target === 'existing' ? Ids::make('f', 'table') : Ids::make('f', 'tablename');
        }
        if ($field === 'target') {
            return Ids::make('f', 'target');
        }
        if ($field !== null && preg_match('/^col-([0-9]{1,3})\z/', $field, $m) === 1) {
            if ($target === 'existing') {
                return Ids::make('f', 'field', $m[1]);
            }
            foreach (['problem.name.', 'problem.text.', 'problem.field_name.'] as $prefix) {
                if (str_starts_with($problem->key, $prefix)) {
                    return Ids::make('f', 'name', $m[1]);
                }
            }

            return Ids::make('f', 'type', $m[1]);
        }

        return null;
    }

    /** A type menu entry's name: "Text", "Link to Rooms", or "The field's type" for a type with no import name. */
    private function typeName(string $type, ?string $tableName): string
    {
        $view = $this->context->view();
        if (str_starts_with($type, 'link:')) {
            return $view->text('import.type.link', ['table' => self::shown((string) $tableName)]);
        }

        return $view->text(in_array($type, self::TYPES, true) ? 'import.type.' . $type : 'import.type.other');
    }

    /**
     * A warning's parameters for its string: headings cut for the screen,
     * type ids as type names, limits as grouped numbers.
     *
     * @return array<string, string|int>
     */
    private function warningParams(ReviewWarning $warning): array
    {
        $format = $this->context->view()->format();
        $params = [];
        foreach ($warning->params as $name => $value) {
            $params[$name] = is_string($value) ? self::shown($value) : $value;
        }
        $params['count'] = is_int($warning->params['count'] ?? null) ? $warning->params['count'] : 0;
        if ($warning->kind === 'unreadable') {
            $type = (string) ($warning->params['type'] ?? '');
            $params['type'] = lcfirst($this->typeName($type, str_starts_with($type, 'link:') ? '' : null));
        }
        if ($warning->kind === 'over_limit') {
            $params['max'] = $format->number(is_int($warning->params['max'] ?? null) ? $warning->params['max'] : 0);
        }
        if ($warning->kind === 'preview_partial') {
            $params['count'] = $format->number($params['count']);
        }
        foreach (['column', 'table', 'field', 'items'] as $name) {
            $params[$name] = is_string($params[$name] ?? null) ? $params[$name] : '';
        }

        return $params;
    }

    /** File-derived text cut to MAX_SHOWN characters with "…" (escaping is the template's). */
    private static function shown(string $text): string
    {
        if (mb_strlen($text, 'UTF-8') <= self::MAX_SHOWN) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_SHOWN, 'UTF-8') . '…';
    }
}
