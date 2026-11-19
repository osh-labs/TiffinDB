<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\SearchResultHit;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;
use TiffinDB\Web\Sidebar;

/**
 * Search results across every table of a project
 * (docs/design/m2/list-and-search.md section 4). The search text comes
 * from the query string `q` and is normalised by the core; an unknown
 * project, or a Member without a grant, is NotFound there (404).
 *
 * Templates receive plain values only.
 */
final class SearchController
{
    /** An excerpt is at most this many characters, ellipses included. */
    public const EXCERPT_LENGTH = 120;

    /** A cut excerpt starts at most this many characters before the match. */
    public const EXCERPT_BEFORE = 40;

    public function __construct(private readonly Context $context)
    {
    }

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $q = $request->query['q'] ?? '';
        $q = is_string($q) ? $q : '';
        $results = $this->context->services()->operations()->records()->search($this->context->actor(), $params['project'] ?? '', $q);
        $view = $this->context->view();
        $format = $view->format();
        $project = $results->project;
        $search = $results->search;

        $itemNames = [];
        foreach ($results->tables as $summary) {
            $itemNames[$summary->table->key] = RecordListController::capital($summary->table->itemName);
        }

        $groups = [];
        foreach ($results->groups as $group) {
            $table = $group->table;
            $base = '/projects/' . $project->key . '/tables/' . $table->key;
            $hits = [];
            foreach ($group->hits as $hit) {
                $hits[] = [
                    'path' => $base . '/records/' . $hit->id,
                    'title' => $hit->title === null || $hit->title === ''
                        ? $view->text('lists.untitled', ['Item' => $itemNames[$table->key] ?? '', 'id' => (string) $hit->id])
                        : $hit->title,
                    'match' => $this->match($hit, (string) $search),
                ];
            }
            $groups[] = [
                'key' => $table->key,
                'name' => $table->name,
                'total' => $group->total,
                'hits' => $hits,
                'more' => $group->total > count($group->hits) ? ['path' => $base, 'count' => $format->number($group->total)] : null,
            ];
        }

        $lead = null;
        if ($results->problem === null && $search === null) {
            $lead = $view->text('search.lead_empty', ['project' => $project->name]);
        } elseif ($search !== null && $results->total > 0) {
            $lead = $view->text('search.lead', ['count' => $results->total, 'q' => $search]);
        }

        return $this->context->page('search', [
            'title' => $view->text('search.page_title', ['project' => $project->name]),
            'layout' => 'list',
            'sidebar' => Sidebar::build($project->key, $project->name, $results->tables, $results->trashCount, null),
            'search' => ['project' => $project->key, 'projectName' => $project->name, 'q' => $q === '' ? null : $q],
            'heading' => $view->text('search.title'),
            'lead' => $lead,
            'problem' => $results->problem === null ? null : $format->problem($results->problem),
            'q' => $search ?? '',
            'projectName' => $project->name,
            'groups' => $groups,
            'none' => $search !== null && $results->total === 0,
        ]);
    }

    /** "{field}: {excerpt}" for the first matching field that is not the title, or null. */
    private function match(SearchResultHit $hit, string $search): ?string
    {
        if ($hit->field === null || $hit->text === null) {
            return null;
        }

        return $this->context->view()->text('search.match', ['field' => $hit->field->name, 'excerpt' => self::excerpt($hit->text, $search)]);
    }

    /**
     * The matching value as one line (line breaks become spaces), at most
     * EXCERPT_LENGTH characters: when longer, it starts at most
     * EXCERPT_BEFORE characters before the match, with "…" at each cut end.
     */
    public static function excerpt(string $text, string $search): string
    {
        $line = (string) preg_replace('/\r\n|\r|\n/u', ' ', $text);
        $length = mb_strlen($line);
        if ($length <= self::EXCERPT_LENGTH) {
            return $line;
        }
        $at = $search === '' ? false : stripos($line, $search);
        $match = $at === false ? 0 : mb_strlen(substr($line, 0, $at));
        $start = max(0, $match - self::EXCERPT_BEFORE);
        $room = self::EXCERPT_LENGTH - ($start > 0 ? 1 : 0);
        $rest = $length - $start;
        if ($rest <= $room) {
            $body = mb_substr($line, $start);
            $tail = '';
        } else {
            $body = mb_substr($line, $start, $room - 1);
            $tail = "\u{2026}";
        }

        return ($start > 0 ? "\u{2026}" : '') . $body . $tail;
    }
}
