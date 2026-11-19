<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ArchiveImportResult;
use TiffinDB\Core\App\ArchiveOperations;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Import a project archive (docs/design/m3/archive.md; M3 spec part 2
 * section 3.1): the upload form, the "name already taken" page, and the
 * redirect to the new project with a notice.
 *
 * ArchiveOperations decides everything: importScreen() is asked first on
 * every route, so a Member gets the 403 page before any upload is looked
 * at; a kept job is found only for the Admin who uploaded it (NotFound
 * for any other key, the 404 page). An upload PHP reports as missing or
 * failed is refused here without calling the core (spec section 3.1).
 */
final class ArchiveController
{
    /** Core field names => element ids. */
    private const FIELDS = ['file' => 'f-file', 'name' => 'f-name'];

    /** The longest uploaded name kept for the "has been checked" sentence. */
    private const MAX_FILE_NAME = 255;

    /** Characters never shown in a file name: as IoSupport::clientName removes them. */
    private const HIDDEN_CHARACTERS = '/(?![\x{200C}\x{200D}])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /projects/import. */
    public function form(Request $request): Response
    {
        $max = $this->archives()->importScreen($this->context->actor())->maxBytes;

        return $this->formPage($max, '', [], 200);
    }

    /** POST /projects/import (multipart). */
    public function import(Request $request): Response
    {
        $actor = $this->context->actor();
        $max = $this->archives()->importScreen($actor)->maxBytes;
        $name = $request->input('name');
        $file = $request->file('file');
        $problem = $file === null ? 'problem.upload.missing' : $file->problem();
        if ($file === null || $file->tmpPath === null || $problem !== null) {
            return $this->formPage($max, $name, [new Problem('file', $problem ?? 'problem.upload.failed')], 422);
        }
        $clientName = self::fileName($file->clientName);
        try {
            $result = $this->archives()->import($actor, $file->tmpPath, $file->clientName, $name === '' ? null : $name);
        } catch (ValidationFailed $failed) {
            $job = self::keptJob($failed);
            if ($job !== null) {
                return $this->namePage($max, $job, $clientName, $name, $failed->problems);
            }

            return $this->formPage($max, $name, $failed->problems, 422);
        }

        return $this->done($result);
    }

    /** @param array<string, string> $params POST /projects/import/{job}: another name for a checked archive. */
    public function rename(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $max = $this->archives()->importScreen($actor)->maxBytes;
        $name = $request->input('name');
        $clientName = self::fileName($request->input('file_name'));
        try {
            $result = $this->archives()->importWithName($actor, $params['job'] ?? '', $name);
        } catch (ValidationFailed $failed) {
            $job = self::keptJob($failed);
            if ($job !== null) {
                return $this->namePage($max, $job, $clientName, $name, $failed->problems);
            }

            // The kept archive was refused after all: start again with a new upload.
            return $this->formPage($max, $name, $failed->problems, 422);
        }

        return $this->done($result);
    }

    private function done(ArchiveImportResult $result): Response
    {
        $view = $this->context->view();
        $report = $result->report;
        $changes = [];
        foreach (
            [
                ['archive.change.values', $report->valuesEmptiedTotal()],
                ['archive.change.links', $report->linksDropped],
                ['archive.change.forms_dropped', $report->formsDropped],
                ['archive.change.forms_extended', $report->formsExtended],
                ['archive.change.files', $report->filesMissing],
            ] as [$key, $count]
        ) {
            if ($count > 0) {
                $changes[] = $view->text($key, ['count' => $count]);
            }
        }
        $project = $result->project;
        if ($changes === []) {
            $this->context->flash('notice.archive_imported', ['name' => $project->name]);
        } else {
            $this->context->flash('notice.archive_imported_changes', ['name' => $project->name, 'list' => implode(', ', $changes)]);
        }

        return $this->context->redirect('/projects/' . $project->key . '/tables');
    }

    /** The kept job key of a name problem (ArchiveOperations::JOB_PARAM), or null. */
    private static function keptJob(ValidationFailed $failed): ?string
    {
        foreach ($failed->problems as $problem) {
            $job = $problem->params[ArchiveOperations::JOB_PARAM] ?? null;
            if (is_string($job) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $job) === 1) {
                return $job;
            }
        }

        return null;
    }

    /**
     * The uploaded file's name as plain text for the page: valid UTF-8,
     * at most MAX_FILE_NAME characters, with the characters the core
     * removes from client names (IoSupport::clientName) removed too:
     * controls (Cc), format characters (Cf: bidi overrides and isolates,
     * zero-width space, byte order mark, soft hyphen, tag characters) other
     * than the zero-width joiner and non-joiner, and line and paragraph
     * separators (Zl, Zp). So a forged `file_name` cannot show reversed or
     * hidden text. '' when it cannot be shown.
     */
    private static function fileName(string $name): string
    {
        if (preg_match('//u', $name) !== 1) {
            return '';
        }
        $clean = (string) preg_replace(self::HIDDEN_CHARACTERS, '', $name);

        return trim(mb_substr($clean, 0, self::MAX_FILE_NAME, 'UTF-8'));
    }

    /**
     * Problems for the summary and the fields, with the size limit shown
     * as a file size and the kept job key left out of the texts.
     *
     * @param list<Problem> $problems
     * @return array{0: list<array{field: string|null, text: string}>, 1: array<string, list<string>>}
     */
    private function problems(array $problems, int $max): array
    {
        $format = $this->context->view()->format();
        $list = [];
        $errors = [];
        foreach ($problems as $problem) {
            $params = $problem->params;
            unset($params[ArchiveOperations::JOB_PARAM]);
            if ($problem->key === 'problem.upload.too_large') {
                $params['max'] = $format->fileSize($max);
            }
            $text = $format->problemText($problem->key, $params);
            $id = $problem->field === null ? null : (self::FIELDS[$problem->field] ?? null);
            $list[] = ['field' => $id, 'text' => $text];
            if ($id !== null) {
                $errors[$id][] = $text;
            }
        }

        return [$list, $errors];
    }

    private function limit(int $coreMax): int
    {
        return $this->context->services()->uploadLimits()->effective($coreMax);
    }

    /** @param list<Problem> $problems */
    private function formPage(int $coreMax, string $name, array $problems, int $status): Response
    {
        $max = $this->limit($coreMax);
        [$list, $errors] = $this->problems($problems, $max);

        return $this->context->page('archive/form', $this->pageData($max, $name, $list, $errors), $status, 'projects');
    }

    /** @param list<Problem> $problems */
    private function namePage(int $coreMax, string $job, string $fileName, string $name, array $problems): Response
    {
        $max = $this->limit($coreMax);
        [$list, $errors] = $this->problems($problems, $max);
        $data = $this->pageData($max, $name, $list, $errors);
        $data['job'] = $job;
        $data['fileName'] = $fileName;

        return $this->context->page('archive/name', $data, 422, 'projects');
    }

    /**
     * @param list<array{field: string|null, text: string}> $list
     * @param array<string, list<string>>                   $errors
     * @return array<string, mixed>
     */
    private function pageData(int $max, string $name, array $list, array $errors): array
    {
        $view = $this->context->view();

        return [
            'title' => $view->text('archive.title'),
            'layout' => 'io',
            'breadcrumb' => [
                ['label' => $view->text('projects.crumb'), 'path' => '/projects'],
                ['label' => $view->text('archive.crumb'), 'path' => null],
            ],
            'problems' => $list,
            'fileProblems' => $errors['f-file'] ?? [],
            'nameError' => $errors['f-name'][0] ?? null,
            'name' => $name,
            'max' => $view->format()->fileSize($max),
            'maxLength' => ProjectOperations::MAX_NAME,
        ];
    }

    private function archives(): ArchiveOperations
    {
        return $this->context->services()->operations()->archives();
    }
}
