<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\FormSourceScreen;
use TiffinDB\Core\App\FormUploadScreen;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Forms\FormReport;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The form source view, the form file download, and the form file upload
 * (docs/design/m5/source.md; M5 spec part 2 sections 4.2 and 6.6).
 *
 * Security-critical. Every decision about access and the table is made by
 * FormOperations (forms.edit, Admin only: a Member is AccessDenied, an
 * ungranted person, an unknown project, and a table key that is not a
 * table of the project are NotFound, which the kernel answers). Addresses
 * are built only from the keys the core returned. Every POST has passed
 * the kernel's Origin and CSRF checks before it reaches this class.
 *
 * Form text reaches a page only as the content of the source text area,
 * through e() in the template; a refused text is shown back exactly as
 * typed (escaped), and no other part of it is ever printed: a problem
 * line holds only the message, its line, and the element and attribute
 * names FormRejected lets through (escaped like every value). The
 * download is Response::attachmentText (attachment, application/
 * octet-stream, no-store, the sandboxed file policy, nosniff). An upload
 * is refused at the file field before the core is called when PHP did not
 * accept it; the core checks the size before it reads a byte, and a
 * refused upload stores nothing.
 */
final class FormSourceController
{
    /** The upload field's name and the file input's id. */
    private const FIELD = 'file';

    /** The text area's id: the target of the error summary's link. */
    private const SOURCE = 'source';

    /** What the file input suggests; the core decides from the bytes. */
    private const ACCEPT = '.html,.htm,.txt';

    /** Message keys whose line is the enclosing element's ("Near line"; M5 spec section 9.5). */
    private const NEAR = ['problem.form.text', 'problem.form.text_value', 'problem.form.content', 'problem.form.node_type'];

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------------ source view

    /** @param array<string, string> $params GET .../form/source */
    public function show(Request $request, array $params): Response
    {
        [$project, $table] = self::ids($params);
        $screen = $this->context->services()->operations()->forms()->source($this->context->actor(), $project, $table);

        return $this->sourcePage($screen, 200);
    }

    /** @param array<string, string> $params POST .../form/source (`text`, `base`, `do`) */
    public function submit(Request $request, array $params): Response
    {
        [$project, $table] = self::ids($params);
        $screen = $this->context->services()->operations()->forms()->source($this->context->actor(), $project, $table, [
            'text' => $request->input('text'),
            'base' => $request->input('base'),
            'do' => $request->input('do'),
        ]);
        $saved = $screen->saved;
        if ($saved !== null) {
            $key = match (true) {
                !$saved->changed => 'notice.form_source_unchanged',
                $saved->rewritten => 'notice.form_source_rewritten',
                default => 'notice.form_source_saved',
            };
            $this->context->flash($key, $saved->changed ? ['table' => $screen->table->name] : []);

            return $this->context->redirect(self::base($screen->project->key, $screen->table->key) . '/form/source');
        }

        return $this->sourcePage($screen, $screen->problems === [] ? 200 : 422);
    }

    // ------------------------------------------------------------------ download

    /** @param array<string, string> $params GET .../form/download */
    public function download(Request $request, array $params): Response
    {
        [$project, $table] = self::ids($params);
        $file = $this->context->services()->operations()->forms()->download($this->context->actor(), $project, $table);

        return Response::attachmentText($file->text, $file->fileName);
    }

    // ------------------------------------------------------------------ upload

    /** @param array<string, string> $params GET .../form/upload */
    public function uploadForm(Request $request, array $params): Response
    {
        return $this->uploadPage($this->uploadScreen($params), [], 200);
    }

    /** @param array<string, string> $params POST .../form/upload (multipart, field `file`) */
    public function upload(Request $request, array $params): Response
    {
        // Authorises first, and checks the table.
        $screen = $this->uploadScreen($params);
        $file = $request->file(self::FIELD);
        $problem = $file === null ? 'problem.upload.missing' : $file->problem();
        if ($problem !== null || $file === null || $file->tmpPath === null) {
            return $this->uploadPage($screen, [new Problem(self::FIELD, $problem ?? 'problem.upload.failed')], 422);
        }
        try {
            $saved = $this->context->services()->operations()->forms()->upload(
                $this->context->actor(),
                $screen->project->key,
                $screen->table->key,
                $file->tmpPath,
            );
        } catch (ValidationFailed $failed) {
            return $this->uploadPage($screen, $failed->problems, 422);
        }
        $this->context->flash($saved->rewritten ? 'notice.form_uploaded_rewritten' : 'notice.form_uploaded', ['table' => $screen->table->name]);

        return $this->context->redirect(self::base($screen->project->key, $screen->table->key) . '/form/source');
    }

    // ------------------------------------------------------------------ pages

    private function sourcePage(FormSourceScreen $screen, int $status): Response
    {
        $view = $this->context->view();
        $base = self::base($screen->project->key, $screen->table->key);

        $summary = [];
        $errors = [];
        foreach ($screen->problems as $problem) {
            $text = $this->problemLine($problem);
            if ($problem->field === self::SOURCE) {
                $summary[] = ['field' => self::SOURCE, 'text' => $text];
                $errors[] = $text;
            } else {
                $summary[] = ['field' => null, 'text' => $text];
            }
        }
        $stored = $screen->storedProblem === null ? null : $this->problemLine($screen->storedProblem);

        return $this->context->page('forms/source', [
            'title' => $view->text('forms.source.title', ['table' => $screen->table->name]),
            'layout' => 'form',
            'breadcrumb' => $this->crumbs($screen, $view->text('forms.source.crumb')),
            'problems' => $summary,
            'table' => $screen->table->name,
            'base' => $base,
            'action' => $base . '/form/source',
            'cancel' => $base . '/form',
            'text' => $screen->text,
            'saveBase' => $screen->base,
            'state' => $screen->state,
            'storedProblem' => $stored,
            'errors' => $errors,
            'report' => $screen->report === null || $screen->problems !== [] ? null : self::report($screen->report),
        ], $status, 'projects');
    }

    /** @param list<Problem> $problems from the upload (422), else [] */
    private function uploadPage(FormUploadScreen $screen, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $base = self::base($screen->project->key, $screen->table->key);
        $limit = $view->format()->fileSize($this->context->services()->uploadLimits()->effective($screen->maxBytes));

        $summary = [];
        $errors = [];
        foreach ($problems as $problem) {
            if ($problem->key === 'problem.upload.too_large') {
                $problem = new Problem($problem->field, $problem->key, ['max' => $limit] + $problem->params);
            }
            $text = $this->problemLine($problem);
            $summary[] = ['field' => self::FIELD, 'text' => $text];
            $errors[] = $text;
        }

        return $this->context->page('forms/upload', [
            'title' => $view->text('forms.upload.title', ['table' => $screen->table->name]),
            'layout' => 'form-narrow',
            'breadcrumb' => $this->crumbs($screen, $view->text('forms.upload.crumb')),
            'problems' => $summary,
            'table' => $screen->table->name,
            'base' => $base,
            'action' => $base . '/form/upload',
            'cancel' => $base . '/form',
            'accept' => self::ACCEPT,
            'errors' => $errors,
        ], $status, 'projects');
    }

    /**
     * A problem as the source view writes it (docs/design/m5/source.md
     * section 4): "Line {line}: ", "Near line {line}: " for the text
     * reasons, or the message alone when the line is not known. Plain text;
     * the template escapes it.
     */
    private function problemLine(Problem $problem): string
    {
        $view = $this->context->view();
        $format = $view->format();
        $params = [];
        foreach ($problem->params as $name => $value) {
            if ($name !== 'line') {
                $params[$name] = $name === 'max' && is_int($value) ? $format->number($value) : $value;
            }
        }
        $message = $format->problemText($problem->key, $params);
        $line = $problem->params['line'] ?? null;
        if (!is_int($line) || $line < 1) {
            return $view->text('forms.source.no_line', ['message' => $message]);
        }
        $key = in_array($problem->key, self::NEAR, true) ? 'forms.source.near_line' : 'forms.source.at_line';

        return $view->text($key, ['line' => $format->number($line), 'message' => $message]);
    }

    /**
     * The report as the forms/report partial takes it.
     *
     * @return array{unknown: int, notShown: list<array{name: string, required: bool}>, requiredHidden: bool, images: int, emptyLinks: int}
     */
    private static function report(FormReport $report): array
    {
        $notShown = [];
        foreach ($report->notOnForm as $field) {
            $notShown[] = ['name' => $field->name, 'required' => $field->required];
        }

        return [
            'unknown' => count($report->unknownKeys),
            'notShown' => $notShown,
            'requiredHidden' => $report->requiredNotOnForm !== [],
            'images' => count($report->imagesNotShown),
            'emptyLinks' => $report->emptyLinks,
        ];
    }

    /** @param array<string, string> $params */
    private function uploadScreen(array $params): FormUploadScreen
    {
        [$project, $table] = self::ids($params);

        return $this->context->services()->operations()->forms()->uploadScreen($this->context->actor(), $project, $table);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: string, 1: string}
     */
    private static function ids(array $params): array
    {
        return [$params['project'] ?? '', $params['table'] ?? ''];
    }

    /** The table's path, `/projects/{p}/tables/{t}`, from keys the core returned. */
    private static function base(string $project, string $table): string
    {
        return '/projects/' . $project . '/tables/' . $table;
    }

    /**
     * Breadcrumb: Projects, the project, the table (its Fields page), Form
     * (the editor), then the page.
     *
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(FormSourceScreen|FormUploadScreen $screen, string $current): array
    {
        $table = $screen->table;

        return ProjectController::crumbs($this->context, $screen->project->key, $screen->project->name, [$table->key, $table->name], [
            ['label' => $this->context->view()->text('forms.editor.crumb'), 'path' => self::base($screen->project->key, $table->key) . '/form'],
            ['label' => $current, 'path' => null],
        ]);
    }
}
