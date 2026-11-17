<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * Sharing a project with Members (docs/design/m1/people-and-sharing.md
 * section 6). The core decides who may see this (`project.manage`): a
 * Member gets the 403 page, or the 404 page for a project not shared with
 * them.
 */
final class SharingController
{
    public function __construct(private readonly Context $context)
    {
    }

    /** GET /projects/{project}/sharing. */
    public function form(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $projects = $this->projects();
        $key = $params['project'] ?? '';
        $rows = $projects->sharing($actor, $key);
        $project = $projects->get($actor, $key);

        $members = [];
        $inactiveGranted = 0;
        foreach ($rows as $row) {
            if ($row->member->active) {
                $members[] = [
                    'key' => $row->member->key,
                    'name' => $row->member->displayName,
                    'username' => $row->member->username,
                    'granted' => $row->granted,
                ];
            } elseif ($row->granted) {
                $inactiveGranted++;
            }
        }
        $view = $this->context->view();

        return $this->context->page('sharing', [
            'title' => $view->text('sharing.title', ['project' => $project->name]),
            'breadcrumb' => [
                ['label' => $view->text('shell.nav.projects'), 'path' => '/projects'],
                ['label' => $project->name, 'path' => '/projects/' . $project->key . '/tables'],
                ['label' => $view->text('sharing.crumb'), 'path' => null],
            ],
            'key' => $project->key,
            'project' => $project->name,
            'members' => $members,
            'inactiveGranted' => $inactiveGranted,
        ], 200, 'projects');
    }

    /** POST /projects/{project}/sharing. */
    public function save(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $projects = $this->projects();
        $key = $params['project'] ?? '';
        $projects->setSharing($actor, $key, $request->inputList('members'));
        $project = $projects->get($actor, $key);
        $this->context->flash('notice.sharing_saved', ['project' => $project->name]);

        return $this->context->redirect('/projects');
    }

    private function projects(): ProjectOperations
    {
        return $this->context->services()->operations()->projects();
    }
}
