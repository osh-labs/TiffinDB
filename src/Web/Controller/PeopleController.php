<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web\Controller;

use LogicException;
use TiffinDB\Core\App\PeopleOperations;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Web\Context;
use TiffinDB\Web\Request;
use TiffinDB\Web\Response;

/**
 * The people screens (docs/design/m1/people-and-sharing.md sections 2 to
 * 5): the list, adding a person, the person page with its four forms, and
 * the confirmation before stopping someone from signing in.
 *
 * Every rule (who may do this, the last active Admin, oneself) is the
 * core's (ADR 0012): each action calls one PeopleOperations method and
 * maps the problems it reports to the page's inputs. The controller only
 * chooses which controls to show, from what the core returns.
 */
final class PeopleController
{
    /** Core field names => element ids on the Add a person form (section 3). */
    private const NEW_FIELDS = [
        'display_name' => 'person-name',
        'username' => 'person-username',
        'role' => 'person-role',
        'temporary_password' => 'person-password',
    ];

    /** Core field names => element ids on the details form of the person page (section 4). */
    private const DETAILS_FIELDS = ['display_name' => 'edit-name', 'username' => 'edit-username', 'role' => 'edit-role'];

    /** Core field names => element ids on the password form of the person page (section 4). */
    private const PASSWORD_FIELDS = ['temporary_password' => 'reset-password'];

    public function __construct(private readonly Context $context)
    {
    }

    /** GET /admin/people. */
    public function index(Request $request): Response
    {
        $actor = $this->context->actor();
        $rows = [];
        foreach ($this->people()->list($actor) as $summary) {
            $person = $summary->person;
            $kind = $person->isAdmin() ? 'all' : ($summary->projectCount === 0 ? 'none' : 'count');
            $rows[] = [
                'key' => $person->key,
                'name' => $person->displayName,
                'username' => $person->username,
                'roleKey' => self::roleKey($person->role),
                'projects' => $kind,
                'projectCount' => $summary->projectCount,
                'active' => $person->active,
                'self' => $person->key === $actor->userKey,
            ];
        }

        return $this->context->page('people/index', [
            'title' => $this->text('people.title'),
            'people' => $rows,
        ], 200, 'people');
    }

    /** GET /admin/people/new. */
    public function newForm(Request $request): Response
    {
        $actor = $this->context->actor();
        $this->context->services()->operations()->auth()->require($actor, Permission::ManagePeople);

        return $this->newPage([
            'name' => '',
            'username' => '',
            'role' => 'member',
            'password' => $this->people()->suggestPassword($actor),
            'projects' => [],
        ], [], 200);
    }

    /** POST /admin/people. */
    public function create(Request $request): Response
    {
        $actor = $this->context->actor();
        $this->context->services()->operations()->auth()->require($actor, Permission::ManagePeople);
        $values = [
            'name' => $request->input('display_name'),
            'username' => $request->input('username'),
            'role' => $request->input('role'),
            'password' => $request->input('temporary_password'),
            'projects' => $request->inputList('projects'),
        ];
        $role = self::role($values['role']);
        if ($role === null) {
            return $this->newPage($values, [self::roleProblem()], 422);
        }
        try {
            $person = $this->people()->create($actor, $values['username'], $values['name'], $role, $values['password'], $values['projects']);
        } catch (ValidationFailed $e) {
            return $this->newPage($values, $e->problems, 422);
        }
        $this->context->flash('notice.person_added', ['name' => $person->displayName]);

        return $this->context->redirect(self::personPath($person));
    }

    /** GET /admin/people/{person}. */
    public function show(Request $request, array $params): Response
    {
        $person = $this->person($params);

        return $this->showPage($person, [], [], 200);
    }

    /** POST /admin/people/{person}: name, username, role. */
    public function update(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $person = $this->person($params);
        $values = [
            'name' => $request->input('display_name'),
            'username' => $request->input('username'),
            'role' => $request->input('role'),
        ];
        $role = self::role($values['role']);
        if ($role === null) {
            return $this->showPage($person, ['details' => $values], $this->mapped([self::roleProblem()], self::DETAILS_FIELDS), 422);
        }
        try {
            $after = $this->people()->update($actor, $person->key, $values['username'], $values['name'], $role);
        } catch (ValidationFailed $e) {
            return $this->showPage($person, ['details' => $values], $this->mapped($e->problems, self::DETAILS_FIELDS), 422);
        }
        if ($this->ownSessionEnded()) {
            return $this->toSignIn();
        }
        $this->context->flash('notice.person_saved', ['name' => $after->displayName]);

        return $this->context->redirect(self::personPath($after));
    }

    /** POST /admin/people/{person}/password: a temporary password. */
    public function password(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $person = $this->person($params);
        $password = $request->input('temporary_password');
        try {
            $this->people()->setTemporaryPassword($actor, $person->key, $password);
        } catch (ValidationFailed $e) {
            return $this->showPage($person, ['password' => $password], $this->mapped($e->problems, self::PASSWORD_FIELDS), 422);
        }
        if ($this->ownSessionEnded()) {
            return $this->toSignIn();
        }
        $this->context->flash('notice.password_set', ['name' => $person->displayName]);

        return $this->context->redirect(self::personPath($person));
    }

    /** POST /admin/people/{person}/projects: the projects a Member can use. */
    public function access(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $person = $this->person($params);
        $keys = $request->inputList('projects');
        try {
            $this->people()->setProjectAccess($actor, $person->key, $keys);
        } catch (ValidationFailed $e) {
            return $this->showPage($person, ['access' => $keys], $this->mapped($e->problems, []), 422);
        }
        $this->context->flash('notice.access_saved', ['name' => $person->displayName]);

        return $this->context->redirect(self::personPath($person));
    }

    /** GET /admin/people/{person}/deactivate: the confirmation page. */
    public function deactivateForm(Request $request, array $params): Response
    {
        $person = $this->person($params);

        return $this->context->page('people/deactivate', [
            'title' => $this->text('people.stop.title', ['name' => $person->displayName]),
            'breadcrumb' => [
                ['label' => $this->text('people.title'), 'path' => '/admin/people'],
                ['label' => $person->displayName, 'path' => self::personPath($person)],
                ['label' => $this->text('people.stop.crumb'), 'path' => null],
            ],
            'key' => $person->key,
            'name' => $person->displayName,
        ], 200, 'people');
    }

    /** POST /admin/people/{person}/deactivate. */
    public function deactivate(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $person = $this->person($params);
        try {
            $this->people()->deactivate($actor, $person->key);
        } catch (ValidationFailed $e) {
            return $this->showPage($person, [], $this->mapped($e->problems, []), 422);
        }
        $this->context->flash('notice.person_stopped', ['name' => $person->displayName]);

        return $this->context->redirect(self::personPath($person));
    }

    /** POST /admin/people/{person}/reactivate. */
    public function reactivate(Request $request, array $params): Response
    {
        $actor = $this->context->actor();
        $person = $this->person($params);
        try {
            $this->people()->reactivate($actor, $person->key);
        } catch (ValidationFailed $e) {
            return $this->showPage($person, [], $this->mapped($e->problems, []), 422);
        }
        $this->context->flash('notice.person_restarted', ['name' => $person->displayName]);

        return $this->context->redirect(self::personPath($person));
    }

    /**
     * The Add a person page.
     *
     * @param array{name: string, username: string, role: string, password: string, projects: list<string>} $values
     * @param list<Problem> $problems
     */
    private function newPage(array $values, array $problems, int $status): Response
    {
        $actor = $this->context->actor();
        $checked = array_flip($values['projects']);
        $projects = [];
        // Every active project: an Admin's own access list holds them all (PeopleOperations::projectAccess).
        foreach ($this->people()->projectAccess($actor, $actor->userKey) as $row) {
            $projects[] = ['key' => $row->project->key, 'name' => $row->project->name, 'checked' => isset($checked[$row->project->key])];
        }
        [$summary, $errors] = $this->mapped($problems, self::NEW_FIELDS);

        return $this->context->page('people/new', [
            'title' => $this->text('people.new.title'),
            'breadcrumb' => [
                ['label' => $this->text('people.title'), 'path' => '/admin/people'],
                ['label' => $this->text('people.new.title'), 'path' => null],
            ],
            'problems' => $summary,
            'errors' => $errors,
            'name' => $values['name'],
            'username' => $values['username'],
            'role' => $values['role'] === 'admin' ? 'admin' : 'member',
            'password' => $values['password'],
            'projects' => $projects,
        ], $status, 'people');
    }

    /**
     * The person page. $submitted holds the values of the form that failed
     * (`details`, `password`, or `access`), printed back instead of the
     * stored ones.
     *
     * @param array{details?: array{name: string, username: string, role: string}, password?: string, access?: list<string>} $submitted
     * @param array{0?: list<array{field: ?string, text: string}>, 1?: array<string, string>} $mapped
     */
    private function showPage(UserRecord $person, array $submitted, array $mapped, int $status): Response
    {
        $actor = $this->context->actor();
        $people = $this->people();
        $self = $person->key === $actor->userKey;

        $activeAdmins = 0;
        foreach ($people->list($actor) as $summary) {
            if ($summary->person->isAdmin() && $summary->person->active) {
                $activeAdmins++;
            }
        }

        $access = [];
        if (!$person->isAdmin()) {
            $wanted = isset($submitted['access']) ? array_flip($submitted['access']) : null;
            foreach ($people->projectAccess($actor, $person->key) as $row) {
                $access[] = [
                    'key' => $row->project->key,
                    'name' => $row->project->name,
                    'checked' => $wanted === null ? $row->granted : isset($wanted[$row->project->key]),
                ];
            }
        }

        $details = $submitted['details'] ?? [
            'name' => $person->displayName,
            'username' => $person->username,
            'role' => $person->role->value,
        ];

        return $this->context->page('people/show', [
            'title' => $person->displayName,
            'breadcrumb' => [
                ['label' => $this->text('people.title'), 'path' => '/admin/people'],
                ['label' => $person->displayName, 'path' => null],
            ],
            'problems' => $mapped[0] ?? [],
            'errors' => $mapped[1] ?? [],
            'key' => $person->key,
            'name' => $person->displayName,
            'username' => $person->username,
            'roleText' => $this->text(self::roleKey($person->role)),
            'active' => $person->active,
            'isAdmin' => $person->isAdmin(),
            'self' => $self,
            'lastAdmin' => $person->isAdmin() && $person->active && $activeAdmins <= 1,
            'details' => [
                'name' => $details['name'],
                'username' => $details['username'],
                'role' => $details['role'] === 'admin' ? 'admin' : 'member',
            ],
            'access' => $access,
            'password' => $submitted['password'] ?? $people->suggestPassword($actor),
        ], $status, 'people');
    }

    /**
     * The summary and per-field errors (element id => first text) for core
     * problems; a problem whose field the form does not show goes in the
     * summary only.
     *
     * @param list<Problem>         $problems
     * @param array<string, string> $fields core field => element id
     * @return array{0: list<array{field: ?string, text: string}>, 1: array<string, string>}
     */
    private function mapped(array $problems, array $fields): array
    {
        $format = $this->context->view()->format();
        $summary = [];
        $errors = [];
        foreach ($problems as $problem) {
            $text = $format->problem($problem);
            $id = $problem->field === null ? null : ($fields[$problem->field] ?? null);
            $summary[] = ['field' => $id, 'text' => $text];
            if ($id !== null && !isset($errors[$id])) {
                $errors[$id] = $text;
            }
        }

        return [$summary, $errors];
    }

    /** @param array<string, string> $params */
    private function person(array $params): UserRecord
    {
        return $this->people()->get($this->context->actor(), $params['person'] ?? '');
    }

    private function people(): PeopleOperations
    {
        return $this->context->services()->operations()->people();
    }

    /**
     * Whether the core ended the acting Admin's own session (a change of
     * their own role or username, or a temporary password for themselves).
     */
    private function ownSessionEnded(): bool
    {
        $token = $this->context->sessionToken();
        if ($token === null) {
            throw new LogicException('The people screens need a session.');
        }

        return $this->context->services()->operations()->auth()->resume($token) === null;
    }

    /** After the acting Admin's own session ended: to sign-in, deleting the cookie. */
    private function toSignIn(): Response
    {
        return $this->context->redirect('/sign-in')->withCookie($this->context->sessionCookie(null));
    }

    /** @param array<string, string|int> $params */
    private function text(string $key, array $params = []): string
    {
        return $this->context->view()->text($key, $params);
    }

    private static function personPath(UserRecord $person): string
    {
        return '/admin/people/' . $person->key;
    }

    private static function role(string $value): ?Role
    {
        return Role::tryFrom($value);
    }

    private static function roleKey(Role $role): string
    {
        return 'role.' . $role->value;
    }

    /** A role value other than the two offered (only a hand-made request sends one). */
    private static function roleProblem(): Problem
    {
        return new Problem('role', 'problem.options.invalid');
    }
}
