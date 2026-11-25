<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use DateTimeImmutable;
use DateTimeZone;
use TiffinDB\Core\App\RestoreScreen;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The snapshots screen, taking a snapshot, and the restore confirmation
 * (docs/design/m7/snapshots.md; M7 screens spec section 9).
 *
 * Every decision about access is made by the core: each action starts
 * with a SnapshotOperations call (snapshots.manage, Admins only), so a
 * Member gets the 403 page and an ungranted person the 404 page before
 * anything is shown or changed. The `{snapshot}` path segment is checked
 * against the snapshot name pattern first (anything else is 404 without
 * a core call); the core then decides whether it names a snapshot of this
 * project. The restore is re-validated by the core on the POST: a
 * damaged snapshot or a server without locks gives the confirmation
 * again with the error summary and no form (422), a project in use the
 * busy page (503, from the kernel).
 */
final class SnapshotController
{
    /**
     * The project file format version this TiffinDB writes, for the
     * "earlier version" note; the web layer may not name the core's
     * ProjectFile, so SnapshotScreenTest pins this to ProjectFile::VERSION.
     */
    public const FORMAT_VERSION = 4;

    /** A snapshot id: a snapshot file name without `.tiffin` (M7 screens spec section 6.4). */
    private const SNAPSHOT_ID = '/^[0-9]{8}T[0-9]{6}Z-[a-z-]+-[a-z2-7]{4}\z/';

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $screen = $this->context->services()->operations()->snapshots()->screen($this->context->actor(), $params['project'] ?? '');
        $view = $this->context->view();
        $project = $screen->project;
        $path = self::listPath($project->key);

        $rows = [];
        foreach ($screen->snapshots as $snapshot) {
            $when = $this->when($snapshot->takenAt);
            $id = self::idOf($snapshot);
            $rows[] = [
                'when' => $when['text'],
                'datetime' => $when['utc'],
                'reason' => $this->reason($snapshot),
                'size' => $view->format()->fileSize(max(0, $snapshot->size)),
                'restorePath' => $screen->canRestore && $id !== null ? $path . '/' . $id . '/restore' : null,
            ];
        }

        return $this->context->page('snapshots/index', [
            'title' => $view->text('snapshots.title'),
            'layout' => 'settings',
            'breadcrumb' => [
                ['label' => $project->name, 'path' => '/projects/' . $project->key],
                ['label' => $view->text('snapshots.crumb'), 'path' => null],
            ],
            'action' => $path,
            'canRestore' => $screen->canRestore,
            'snapshots' => $rows,
        ], 200, 'projects');
    }

    /** @param array<string, string> $params */
    public function take(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $info = $this->context->services()->operations()->snapshots()->take($this->context->actor(), $key);
        $this->context->flash('notice.snapshot_taken', ['when' => $this->when($info->takenAt)['text']]);

        return $this->context->redirect(self::listPath($key));
    }

    /** @param array<string, string> $params */
    public function restoreForm(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $id = self::checkedId($params['snapshot'] ?? '');
        $actor = $this->context->actor();
        $snapshots = $this->context->services()->operations()->snapshots();
        try {
            $screen = $snapshots->restoreScreen($actor, $key, $id);
        } catch (ValidationFailed $failed) {
            return $this->failedPage($key, $id, $failed->problems);
        }
        if (!$snapshots->screen($actor, $key)->canRestore) {
            return $this->failedPage($key, $id, [new Problem(null, 'problem.lock.unavailable')]);
        }

        return $this->restorePage($screen);
    }

    /** @param array<string, string> $params */
    public function restore(Request $request, array $params): Response
    {
        $key = $params['project'] ?? '';
        $id = self::checkedId($params['snapshot'] ?? '');
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        try {
            $result = $operations->snapshots()->restore($actor, $key, $id);
        } catch (ValidationFailed $failed) {
            return $this->failedPage($key, $id, $failed->problems);
        }
        $params = [
            'project' => $operations->projects()->get($actor, $key)->name,
            'date' => $this->when($result->restored->takenAt)['text'],
        ];
        if ($result->filesMissing > 0) {
            $this->context->flash('notice.snapshot_restored_missing', $params + ['count' => $result->filesMissing]);
        } else {
            $this->context->flash('notice.snapshot_restored', $params);
        }

        return $this->context->redirect(self::listPath($key));
    }

    // ------------------------------------------------------------------ pages

    /** The confirmation (snapshots.md section 3), from RestoreScreen. */
    private function restorePage(RestoreScreen $screen): Response
    {
        $project = $screen->project;
        $path = self::listPath($project->key);
        $id = self::idOf($screen->snapshot);
        if ($id === null) {
            throw new NotFound('No such snapshot.');
        }

        return $this->confirmationPage($project->key, $project->name, $screen->snapshot, [
            'counts' => [
                'then' => $screen->recordsThen,
                'now' => $screen->recordsNow,
                'filesRemoved' => $screen->filesRemoved,
                'filesMissing' => $screen->filesMissing,
                'older' => $screen->formatVersion < self::FORMAT_VERSION,
            ],
            'action' => $path . '/' . $id . '/restore',
            'cancel' => $path,
        ], [], 200);
    }

    /**
     * The confirmation with the error summary and without the form (422):
     * the snapshot cannot be used, or this server cannot lock files. The
     * saved time and reason are shown when the snapshot is still listed.
     *
     * @param list<Problem> $problems
     */
    private function failedPage(string $key, string $id, array $problems): Response
    {
        $actor = $this->context->actor();
        $operations = $this->context->services()->operations();
        $project = $operations->projects()->get($actor, $key);
        $found = null;
        foreach ($operations->snapshots()->list($actor, $project->key) as $snapshot) {
            if (self::idOf($snapshot) === $id) {
                $found = $snapshot;
            }
        }

        return $this->confirmationPage($project->key, $project->name, $found, ['counts' => null, 'action' => null, 'cancel' => null], $problems, 422);
    }

    /**
     * @param array{counts: array<string, int|bool>|null, action: string|null, cancel: string|null} $more
     * @param list<Problem> $problems
     */
    private function confirmationPage(string $key, string $name, ?SnapshotInfo $snapshot, array $more, array $problems, int $status): Response
    {
        $view = $this->context->view();
        [$list] = ProjectController::problems($this->context, $problems, []);
        $facts = null;
        if ($snapshot !== null) {
            $when = $this->when($snapshot->takenAt);
            $facts = ['when' => $when['text'], 'datetime' => $when['utc'], 'reason' => $this->reason($snapshot)];
        }

        return $this->context->page('snapshots/restore', [
            'title' => $view->text('snapshots.restore.title'),
            'layout' => 'record-narrow',
            'breadcrumb' => [
                ['label' => $name, 'path' => '/projects/' . $key],
                ['label' => $view->text('snapshots.crumb'), 'path' => self::listPath($key)],
                ['label' => $view->text('snapshots.restore.crumb'), 'path' => null],
            ],
            'problems' => $list,
            'project' => $name,
            'snapshot' => $facts,
        ] + $more, $status, 'projects');
    }

    // ------------------------------------------------------------------ helpers

    private static function listPath(string $projectKey): string
    {
        return '/projects/' . $projectKey . '/snapshots';
    }

    /** The `{snapshot}` segment when it has the shape of a snapshot id; NotFound (no core call) otherwise. */
    private static function checkedId(string $segment): string
    {
        if (preg_match(self::SNAPSHOT_ID, $segment) !== 1) {
            throw new NotFound('No such snapshot.');
        }

        return $segment;
    }

    /** The id of a listed snapshot (its file name without `.tiffin`), or null when it has another shape. */
    private static function idOf(SnapshotInfo $snapshot): ?string
    {
        if (!str_ends_with($snapshot->file, '.tiffin')) {
            return null;
        }
        $id = substr($snapshot->file, 0, -strlen('.tiffin'));

        return preg_match(self::SNAPSHOT_ID, $id) === 1 ? $id : null;
    }

    private function reason(SnapshotInfo $snapshot): string
    {
        return $this->context->view()->text('snapshots.reason.' . $snapshot->reason->value);
    }

    /**
     * A snapshot time as text in the configured time zone ("3 Nov 2026,
     * 10:00") and as the UTC timestamp for a `time` element.
     *
     * @return array{text: string, utc: string}
     */
    private function when(DateTimeImmutable $at): array
    {
        $view = $this->context->view();
        $zone = $view->config('timezone');
        $local = $at->setTimezone(new DateTimeZone(is_string($zone) && $zone !== '' ? $zone : date_default_timezone_get()));

        return [
            'text' => $view->format()->dateTime($local->format('Y-m-d\TH:i:s'), false),
            'utc' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
