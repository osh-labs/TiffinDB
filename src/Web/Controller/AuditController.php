<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use InvalidArgumentException;
use TiffinDB\Core\App\AuditOperations;
use TiffinDB\Core\App\AuditPage;
use TiffinDB\Core\App\AuditRow;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The activity page, the audit log for Admins (docs/design/m7/audit.md;
 * M7 screens spec section 9).
 *
 * The controller passes the query string's `person`, `project`, `action`,
 * and `page` to AuditOperations::page as strings only; the core ignores a
 * value that names nothing, so the page never answers 422. The core
 * refuses a Member (403) before it reads anything.
 *
 * Every value from the log (display names, project names, detail keys and
 * values, unknown action names) is handed to the template as plain text,
 * which prints it with e(). Sentences are chosen from the actions the
 * core lists (AuditChoices::actions), never from a stored value used as a
 * key. The `datetime` attribute is given only for a stored timestamp of
 * the exact pattern; anything else is shown as text alone.
 */
final class AuditController
{
    /** The query keys the page reads (M7 spec section 7.3). */
    private const QUERY_KEYS = ['person', 'project', 'action', 'page'];

    private const TIMESTAMP = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/';

    private const PATH = '/admin/activity';

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /admin/activity. */
    public function index(Request $request): Response
    {
        $query = [];
        foreach (self::QUERY_KEYS as $key) {
            $value = $request->query[$key] ?? null;
            if (is_string($value)) {
                $query[$key] = $value;
            }
        }
        $page = $this->context->services()->operations()->audit()->page($this->context->actor(), $query);
        $view = $this->context->view();

        $applied = [];
        foreach (['person' => $page->person, 'project' => $page->project, 'action' => $page->action] as $key => $value) {
            if ($value !== null) {
                $applied[$key] = $value;
            }
        }
        $actions = $page->choices->actions;
        $entries = [];
        foreach ($page->rows as $row) {
            $entries[] = $this->entry($row, $actions);
        }
        $from = $page->total === 0 ? 0 : ($page->page - 1) * AuditOperations::PAGE + 1;

        return $this->context->page('audit/index', [
            'title' => $view->text('audit.title'),
            'layout' => 'io',
            'people' => $this->people($page),
            'projects' => $this->projects($page),
            'actions' => $this->actionOptions($page),
            'filtered' => $applied !== [],
            'total' => $page->total,
            'entries' => $entries,
            'pager' => [
                'from' => $from,
                'to' => $from === 0 ? 0 : $from + count($entries) - 1,
                'total' => $page->total,
                'page' => $page->page,
                'pages' => $page->pages,
                'path' => self::PATH,
                'query' => $applied,
            ],
        ], 200, 'activity');
    }

    /**
     * The person menu: Anyone, then every person.
     *
     * @return list<array{value: string, text: string, selected: bool}>
     */
    private function people(AuditPage $page): array
    {
        $view = $this->context->view();
        $options = [['value' => '', 'text' => $view->text('audit.filter.anyone'), 'selected' => $page->person === null]];
        foreach ($page->choices->people as $person) {
            $options[] = [
                'value' => $person['key'],
                'text' => $person['active'] ? $person['name'] : $view->text('audit.filter.inactive', ['name' => $person['name']]),
                'selected' => $person['key'] === $page->person,
            ];
        }

        return $options;
    }

    /**
     * The project menu: Any project, every active project, Deleted projects.
     *
     * @return list<array{value: string, text: string, selected: bool}>
     */
    private function projects(AuditPage $page): array
    {
        $view = $this->context->view();
        $options = [['value' => '', 'text' => $view->text('audit.filter.any_project'), 'selected' => $page->project === null]];
        foreach ($page->choices->projects as $project) {
            $options[] = ['value' => $project['key'], 'text' => $project['name'], 'selected' => $project['key'] === $page->project];
        }
        $options[] = ['value' => AuditOperations::DELETED, 'text' => $view->text('audit.filter.deleted'), 'selected' => $page->project === AuditOperations::DELETED];

        return $options;
    }

    /**
     * The action menu: Anything, then the short words of each action.
     *
     * @return list<array{value: string, text: string, selected: bool}>
     */
    private function actionOptions(AuditPage $page): array
    {
        $view = $this->context->view();
        $options = [['value' => '', 'text' => $view->text('audit.filter.anything'), 'selected' => $page->action === null]];
        foreach ($page->choices->actions as $action) {
            $options[] = ['value' => $action, 'text' => $view->text('audit.action.' . $action . '.short'), 'selected' => $action === $page->action];
        }

        return $options;
    }

    /**
     * One entry as plain text for the template.
     *
     * @param list<string> $actions the actions the core knows
     * @return array{when: string, datetime: ?string, what: string, detail: list<array{key: string, value: string}>}
     */
    private function entry(AuditRow $row, array $actions): array
    {
        $view = $this->context->view();
        $who = $row->actorName ?? $view->text('audit.who.none');
        $project = $row->projectName ?? $view->text('audit.project.unknown');
        if (in_array($row->action, $actions, true)) {
            $what = $view->text('audit.action.' . $row->action, ['who' => $who, 'project' => $project]);
        } else {
            $what = $view->text('audit.unknown', ['who' => $who, 'action' => $row->action]);
        }
        $detail = [];
        foreach ($row->detail as $key => $value) {
            $detail[] = ['key' => (string) $key, 'value' => self::detailText($value)];
        }

        return ['when' => $this->when($row->at), 'datetime' => $this->datetime($row->at), 'what' => $what, 'detail' => $detail];
    }

    /** The time of an entry in the configured time zone, or the stored text when it is not a timestamp. */
    private function when(string $at): string
    {
        if ($this->datetime($at) === null) {
            return $at;
        }
        $text = $this->context->view()->format()->savedAt($at);

        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /** $at when it is a stored timestamp of the exact pattern (a real date and time), else null. */
    private function datetime(string $at): ?string
    {
        if (preg_match(self::TIMESTAMP, $at) !== 1) {
            return null;
        }
        try {
            $this->context->view()->format()->relativeDate($at);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $at;
    }

    /** A detail value as recorded: text as it is, numbers in digits, true, false, or nothing. */
    private static function detailText(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value === true => 'true',
            $value === false => 'false',
            default => '',
        };
    }
}
