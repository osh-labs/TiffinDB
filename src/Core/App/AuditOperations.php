<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditEntry;
use TiffinDB\Core\System\AuditQuery;

/** Reading the audit log (M1 spec section 9.6) and the audit screen (M7 spec section 7.3); Admin-only. */
final class AuditOperations
{
    public const MAX_LIMIT = 500;
    /** Rows per page of the audit screen. */
    public const PAGE = 50;
    /** The project filter value for deleted projects. */
    public const DELETED = 'deleted';
    private const PAGE_NUMBER = '/^[1-9][0-9]{0,5}\z/';

    public function __construct(private readonly Context $c)
    {
    }

    /** @return list<AuditEntry> newest first; $limit 1 to 500 */
    public function recent(Actor $actor, int $limit = 100, int $offset = 0): array
    {
        $this->c->authorizer->require($actor, Permission::ViewAudit);
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new \InvalidArgumentException('The limit must be 1 to ' . self::MAX_LIMIT . ' and the offset 0 or more.');
        }

        return $this->c->audit->recent($limit, $offset);
    }

    /**
     * One page of the audit screen. $query holds the query-string values
     * `person` (a user key), `project` (a project key, or `deleted`),
     * `action` (a value of AuditAction::ALL), and `page`; a value that is
     * not a string, does not match, or names nothing that exists turns its
     * filter off, and the page is clamped to 1..pages.
     *
     * @param array<mixed> $query
     */
    public function page(Actor $actor, array $query): AuditPage
    {
        $this->c->authorizer->require($actor, Permission::ViewAudit);
        $this->c->requireCurrentActor($actor);

        $person = null;
        $actorId = null;
        $value = $query['person'] ?? null;
        if (is_string($value) && Keys::isValid($value)) {
            $found = $this->c->users->byKey($value);
            if ($found !== null) {
                $person = $found->key;
                $actorId = $found->id;
            }
        }
        $project = null;
        $projectId = null;
        $deleted = false;
        $value = $query['project'] ?? null;
        if ($value === self::DELETED) {
            $project = self::DELETED;
            $deleted = true;
        } elseif (is_string($value) && Keys::isValid($value)) {
            $found = $this->c->registry->byKey($value);
            if ($found !== null) {
                $project = $found->key;
                $projectId = $found->id;
            }
        }
        $action = null;
        $value = $query['action'] ?? null;
        if (is_string($value) && in_array($value, AuditAction::ALL, true)) {
            $action = $value;
        }
        $page = 1;
        $value = $query['page'] ?? null;
        if (is_string($value) && preg_match(self::PAGE_NUMBER, $value) === 1) {
            $page = (int) $value;
        }

        $filter = new AuditQuery($actorId, $projectId, $action, $deleted);
        $total = $this->c->audit->count($filter);
        $pages = max(1, intdiv($total + self::PAGE - 1, self::PAGE));
        $page = min(max($page, 1), $pages);
        $rows = [];
        $names = [];
        $projects = [];
        foreach ($this->c->audit->search($filter, self::PAGE, ($page - 1) * self::PAGE) as $entry) {
            $who = null;
            if ($entry->actorUserId !== null) {
                $who = $names[$entry->actorUserId] ??= $this->c->users->byId($entry->actorUserId);
            }
            $projectName = null;
            if ($entry->projectId !== null) {
                $projectName = ($projects[$entry->projectId] ??= $this->c->registry->byId($entry->projectId))?->name;
            } elseif ($entry->action === AuditAction::PROJECT_DELETED && is_string($entry->detail['name'] ?? null)) {
                $projectName = $entry->detail['name'];
            }
            $rows[] = new AuditRow(
                $entry->id,
                $entry->at,
                $who?->displayName,
                $who?->key,
                $entry->action,
                $projectName,
                $entry->targetKind,
                $entry->targetKey,
                $entry->detail,
            );
        }

        return new AuditPage($rows, $total, $page, $pages, $this->choices(), $person, $project, $action);
    }

    private function choices(): AuditChoices
    {
        $people = [];
        foreach ($this->c->users->all() as $user) {
            $people[] = ['key' => $user->key, 'name' => $user->displayName, 'active' => $user->active];
        }
        $projects = [];
        foreach ($this->c->registry->all() as $record) {
            $projects[] = ['key' => $record->key, 'name' => $record->name];
        }

        return new AuditChoices($people, $projects, AuditAction::ALL);
    }
}
