<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\FileScreen;
use TiffinDB\Core\App\UploadScreen;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Web\Context;
use TiffinDB\Web\Ids;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Files attached to records (docs/design/m4/files.md; ADR 0018): the
 * upload page (add and replace), the upload itself, the file page, the
 * remove confirmation and the removal, and the serving route.
 *
 * Security-critical. Every decision about access, the table, the field,
 * the record, and the attachment is made by AttachmentOperations; a
 * record in the trash, a field that is not an attachment field of the
 * table, an attachment the record does not hold in that field, a missing
 * stored file, and an ungranted person are NotFound there, which the
 * kernel answers with the plain 404 page. This controller builds
 * addresses only from keys and ids the core returned, and never a file
 * path: serve() hands the core's ServedFile to Response::file(), which
 * sends the headers of ADR 0018 decision 5 (exact image type inline, or
 * application/octet-stream as an attachment; nosniff and the sandboxed
 * policy from SecurityHeaders).
 *
 * Uploads (M4 spec part 2 section 4 rule 1): the upload page is read
 * first (authorization), then a file PHP did not accept is refused with
 * 422 at the file field before the core is called; the core stages,
 * checks, and stores the file.
 */
final class FileController
{
    /** The upload field's name and the id part of its control (`f-file`). */
    private const FIELD = 'file';

    /** At most this many files per attachment field on one record (ADR 0018 decision 3). */
    private const MAX_FILES = 100;

    /** What an images-only field's file input suggests; the server checks the bytes regardless. */
    private const IMAGE_ACCEPT = ['.png', '.jpg', '.jpeg', '.gif', '.webp'];

    public function __construct(private readonly Context $context)
    {
    }

    // ------------------------------------------------------------------ upload

    /** @param array<string, string> $params GET .../files/{field}/new, with `replace` to replace a file */
    public function uploadForm(Request $request, array $params): Response
    {
        $replace = $request->query['replace'] ?? null;
        $screen = $this->uploadScreen($params, is_string($replace) ? $replace : null);

        return $this->uploadPage($screen, [], 200);
    }

    /** @param array<string, string> $params POST .../files/{field} (multipart, field `file`, hidden `replace`) */
    public function upload(Request $request, array $params): Response
    {
        $replace = $request->input('replace');
        // Authorises first, and checks the record, the field, and the file replaced.
        $screen = $this->uploadScreen($params, $replace === '' ? null : $replace);
        $file = $request->file(self::FIELD);
        $problem = $file === null ? 'problem.upload.missing' : $file->problem();
        if ($problem !== null || $file === null || $file->tmpPath === null) {
            return $this->uploadPage($screen, [new Problem(self::FIELD, $problem ?? 'problem.upload.failed', ['max' => $screen->maxBytes])], 422);
        }
        try {
            $added = $this->context->services()->operations()->attachments()->add(
                $this->context->actor(),
                $screen->project->key,
                $screen->table->key,
                (string) $screen->record->id,
                $screen->field->key,
                $file->tmpPath,
                $file->clientName,
                $screen->replacing === null ? null : (string) $screen->replacing->id,
            );
        } catch (ValidationFailed $failed) {
            return $this->uploadPage($screen, $failed->problems, 422);
        }
        if ($screen->replacing === null) {
            $this->context->flash('notice.file_added', ['name' => $added->originalName]);
        } else {
            $this->context->flash('notice.file_replaced', ['name' => $added->originalName, 'old' => $screen->replacing->originalName]);
        }

        return $this->context->redirect(self::recordPath($screen->project->key, $screen->table->key, $screen->record->id));
    }

    // ------------------------------------------------------------------ file page and removal

    /** @param array<string, string> $params GET .../files/{field}/{attachment} */
    public function show(Request $request, array $params): Response
    {
        $screen = $this->fileScreen($params);
        $view = $this->context->view();
        $project = $screen->project->key;
        $file = $screen->file;
        $base = $this->filesPath($screen);
        $added = $file->createdAt === '' ? null : $this->day($file->createdAt);

        $facts = [
            ['term' => 'files.show.type', 'value' => $view->text(self::typeKey($file->mediaType))],
            ['term' => 'files.show.size', 'value' => $view->format()->fileSize(max(0, $file->size))],
        ];
        if ($added !== null) {
            $facts[] = [
                'term' => 'files.show.added',
                'value' => $screen->addedBy === null ? $added : $view->text('files.show.added_by', ['date' => $added, 'name' => $screen->addedBy]),
            ];
        }

        return $this->context->page('files/show', [
            'title' => $file->originalName,
            'layout' => 'record-narrow',
            'breadcrumb' => $this->crumbs($screen->project->key, $screen->project->name, $screen->table->key, $screen->table->name, $screen->record->id, $this->title($screen), $file->originalName),
            'name' => $file->originalName,
            'field' => self::label($screen->field),
            'record' => $this->title($screen),
            'image' => $screen->inline ? self::servePath($project, $file->id) : null,
            'facts' => $facts,
            'download' => self::servePath($project, $file->id),
            'downloadQuery' => $screen->inline ? ['download' => '1'] : [],
            'replace' => $screen->canEdit ? $base . '/new' : null,
            'replaceQuery' => ['replace' => (string) $file->id],
            'remove' => $screen->canEdit ? $base . '/' . $file->id . '/remove' : null,
            'back' => self::recordPath($project, $screen->table->key, $screen->record->id),
        ]);
    }

    /** @param array<string, string> $params GET .../files/{field}/{attachment}/remove */
    public function removeForm(Request $request, array $params): Response
    {
        // Removing needs records.edit (files.md section 1); the file page itself only records.view.
        $this->context->services()->operations()->auth()->require($this->context->actor(), Permission::EditRecords, $params['project'] ?? '');
        $screen = $this->fileScreen($params);
        $view = $this->context->view();
        $name = $screen->file->originalName;
        $title = $this->title($screen);

        return $this->context->page('files/remove', [
            'title' => $view->text('files.remove.title', ['name' => $name]),
            'layout' => 'record-narrow',
            'breadcrumb' => $this->crumbs($screen->project->key, $screen->project->name, $screen->table->key, $screen->table->name, $screen->record->id, $title, $view->text('files.remove.crumb')),
            'name' => $name,
            'record' => $title,
            'action' => $this->filesPath($screen) . '/' . $screen->file->id . '/remove',
            'cancel' => self::recordPath($screen->project->key, $screen->table->key, $screen->record->id),
        ]);
    }

    /** @param array<string, string> $params POST .../files/{field}/{attachment}/remove */
    public function remove(Request $request, array $params): Response
    {
        [$project, $table, $record, $field, $attachment] = self::ids($params);
        $removed = $this->context->services()->operations()->attachments()
            ->remove($this->context->actor(), $project, $table, $record, $field, $attachment);
        $this->context->flash('notice.file_removed', ['name' => $removed->originalName]);

        // The core accepted these keys and the canonical id, so the address is built from them.
        return $this->context->redirect('/projects/' . $project . '/tables/' . $table . '/records/' . $record);
    }

    // ------------------------------------------------------------------ serving

    /**
     * GET /projects/{project}/files/{attachment}: the stored file itself.
     * `download` is read as exactly `1` (M4 spec part 2 section 4 rule 2);
     * NotFound and AccessDenied reach the kernel, any other failure is a
     * 500 the kernel logs.
     *
     * @param array<string, string> $params
     */
    public function serve(Request $request, array $params): Response
    {
        $served = $this->context->services()->operations()->attachments()->serve(
            $this->context->actor(),
            $params['project'] ?? '',
            $params['attachment'] ?? '',
            ($request->query['download'] ?? null) === '1',
        );

        return Response::file($served->path, $served->mediaType, $served->fileName, $served->inline);
    }

    // ------------------------------------------------------------------ pages

    /**
     * The upload page, adding or replacing (files.md section 2).
     *
     * @param list<Problem> $problems from the upload (422), else []
     */
    private function uploadPage(UploadScreen $screen, array $problems, int $status): Response
    {
        $view = $this->context->view();
        $format = $view->format();
        $limit = $this->context->services()->uploadLimits()->effective($screen->maxBytes);
        $max = $format->fileSize($limit);
        $title = $screen->record->title ?? $this->untitled($screen->table->itemName, $screen->record->id);
        $replacing = $screen->replacing;

        $summary = [];
        $fileProblems = [];
        foreach ($problems as $problem) {
            $params = $problem->params;
            if ($problem->key === 'problem.upload.too_large') {
                $params['max'] = $max;
            }
            $text = $format->problemText($problem->key, $params);
            $summary[] = ['field' => Ids::make('f', self::FIELD), 'text' => $text];
            $fileProblems[] = $text;
        }

        if ($replacing === null) {
            $heading = $view->text('files.upload.title', ['title' => $title]);
            $crumb = $view->text('files.upload.crumb');
        } else {
            $heading = $view->text('files.replace.title', ['name' => $replacing->originalName]);
            $crumb = $view->text('files.replace.crumb');
        }

        return $this->context->page('files/new', [
            'title' => $heading,
            'layout' => 'record-narrow',
            'breadcrumb' => $this->crumbs($screen->project->key, $screen->project->name, $screen->table->key, $screen->table->name, $screen->record->id, $title, $crumb),
            'problems' => $summary,
            'record' => $title,
            'field' => self::label($screen->field),
            'replacing' => $replacing === null ? null : ['id' => (string) $replacing->id, 'name' => $replacing->originalName],
            'action' => self::recordPath($screen->project->key, $screen->table->key, $screen->record->id) . '/files/' . $screen->field->key,
            'cancel' => self::recordPath($screen->project->key, $screen->table->key, $screen->record->id),
            'imagesOnly' => $screen->imagesOnly,
            'accept' => $screen->imagesOnly ? self::IMAGE_ACCEPT : [],
            'max' => $max,
            'fileProblems' => $fileProblems,
            'count' => $screen->count,
            'maxFiles' => $format->number(self::MAX_FILES),
            'item' => $screen->table->itemName,
        ], $status);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, string> $params */
    private function uploadScreen(array $params, ?string $replace): UploadScreen
    {
        [$project, $table, $record, $field] = self::ids($params);

        return $this->context->services()->operations()->attachments()
            ->uploadScreen($this->context->actor(), $project, $table, $record, $field, $replace);
    }

    /** @param array<string, string> $params */
    private function fileScreen(array $params): FileScreen
    {
        [$project, $table, $record, $field, $attachment] = self::ids($params);

        return $this->context->services()->operations()->attachments()
            ->file($this->context->actor(), $project, $table, $record, $field, $attachment);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private static function ids(array $params): array
    {
        return [$params['project'] ?? '', $params['table'] ?? '', $params['record'] ?? '', $params['field'] ?? '', $params['attachment'] ?? ''];
    }

    /** The upload, file, and remove pages' base: `.../records/{r}/files/{f}`. */
    private function filesPath(FileScreen $screen): string
    {
        return self::recordPath($screen->project->key, $screen->table->key, $screen->record->id) . '/files/' . $screen->field->key;
    }

    private static function recordPath(string $project, string $table, int $id): string
    {
        return '/projects/' . $project . '/tables/' . $table . '/records/' . $id;
    }

    private static function servePath(string $project, int $attachment): string
    {
        return '/projects/' . $project . '/files/' . $attachment;
    }

    /** The field's label in sentences ("added to {field}"): its name. */
    private static function label(FieldDef $field): string
    {
        return $field->name;
    }

    /** The M2 type word of a stored media type. */
    private static function typeKey(string $mediaType): string
    {
        return match (true) {
            $mediaType === 'application/pdf' => 'file.type.pdf',
            in_array($mediaType, Response::INLINE_TYPES, true) => 'file.type.image',
            default => 'file.type.other',
        };
    }

    private function title(FileScreen $screen): string
    {
        return $screen->record->title ?? $this->untitled($screen->table->itemName, $screen->record->id);
    }

    /**
     * Breadcrumb: project, table (the list), the record, then the page.
     *
     * @return list<array{label: string, path: string|null}>
     */
    private function crumbs(string $project, string $projectName, string $table, string $tableName, int $record, string $title, string $current): array
    {
        return [
            ['label' => $projectName, 'path' => '/projects/' . $project],
            ['label' => $tableName, 'path' => '/projects/' . $project . '/tables/' . $table],
            ['label' => $title, 'path' => self::recordPath($project, $table, $record)],
            ['label' => $current, 'path' => null],
        ];
    }

    /** "{Item} {id}" for a record without a title. */
    private function untitled(string $itemName, int $id): string
    {
        $item = mb_strtoupper(mb_substr($itemName, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($itemName, 1, null, 'UTF-8');

        return $this->context->view()->text('records.untitled', ['Item' => $item, 'id' => (string) $id]);
    }

    /** The relative day of a stored timestamp, in lower case for today and yesterday (as the record page). */
    private function day(string $timestamp): string
    {
        $view = $this->context->view();
        $day = $view->format()->relativeDate($timestamp);
        if ($day === $view->text('date.today')) {
            return $view->text('date.today_lower');
        }
        if ($day === $view->text('date.yesterday')) {
            return $view->text('date.yesterday_lower');
        }

        return $day;
    }
}
