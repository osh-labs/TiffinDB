<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The route table (ADR 0008 decision 2; M1 spec part 2 section 2.3): a
 * list of [method, pattern, [ControllerClass, 'method'], access]. First
 * match wins, so literal segments such as `new` come before placeholders.
 * `/projects/{project}/tables/{table}` is reserved for M2.
 */

declare(strict_types=1);

use TiffinDB\Web\Controller\AccountController;
use TiffinDB\Web\Controller\FieldController;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Controller\PeopleController;
use TiffinDB\Web\Controller\ProjectController;
use TiffinDB\Web\Controller\SetupController;
use TiffinDB\Web\Controller\SharingController;
use TiffinDB\Web\Controller\SignInController;
use TiffinDB\Web\Controller\TableController;

$field = '/projects/{project}/tables/{table}/fields/{field}';

return [
    ['GET', '/', [HomeController::class, 'show'], 'any'],

    ['GET', '/setup', [SetupController::class, 'show'], 'setup'],
    ['POST', '/setup/data-folder', [SetupController::class, 'dataFolder'], 'setup'],
    ['POST', '/setup/admin', [SetupController::class, 'admin'], 'setup'],

    ['GET', '/sign-in', [SignInController::class, 'form'], 'guest'],
    ['POST', '/sign-in', [SignInController::class, 'submit'], 'guest'],
    ['POST', '/sign-out', [SignInController::class, 'signOut'], 'user'],

    ['GET', '/account/password', [AccountController::class, 'passwordForm'], 'user'],
    ['POST', '/account/password', [AccountController::class, 'password'], 'user'],

    ['GET', '/projects', [ProjectController::class, 'index'], 'user'],
    ['GET', '/projects/new', [ProjectController::class, 'newForm'], 'user'],
    ['POST', '/projects', [ProjectController::class, 'create'], 'user'],
    ['GET', '/projects/{project}', [ProjectController::class, 'open'], 'user'],
    ['GET', '/projects/{project}/settings', [ProjectController::class, 'settingsForm'], 'user'],
    ['POST', '/projects/{project}/settings', [ProjectController::class, 'settings'], 'user'],
    ['GET', '/projects/{project}/sharing', [SharingController::class, 'form'], 'user'],
    ['POST', '/projects/{project}/sharing', [SharingController::class, 'save'], 'user'],

    ['GET', '/projects/{project}/tables', [TableController::class, 'index'], 'user'],
    ['GET', '/projects/{project}/tables/new', [TableController::class, 'newForm'], 'user'],
    ['POST', '/projects/{project}/tables', [TableController::class, 'create'], 'user'],
    ['GET', '/projects/{project}/tables/{table}/fields', [TableController::class, 'fields'], 'user'],
    ['POST', '/projects/{project}/tables/{table}/settings', [TableController::class, 'update'], 'user'],
    ['GET', '/projects/{project}/tables/{table}/delete', [TableController::class, 'deleteForm'], 'user'],
    ['POST', '/projects/{project}/tables/{table}/delete', [TableController::class, 'delete'], 'user'],

    ['GET', '/projects/{project}/tables/{table}/fields/new', [FieldController::class, 'newForm'], 'user'],
    ['POST', '/projects/{project}/tables/{table}/fields', [FieldController::class, 'create'], 'user'],
    ['GET', $field, [FieldController::class, 'editForm'], 'user'],
    ['POST', $field, [FieldController::class, 'update'], 'user'],
    ['GET', $field . '/type', [FieldController::class, 'typeForm'], 'user'],
    ['POST', $field . '/type', [FieldController::class, 'changeType'], 'user'],
    ['POST', $field . '/move', [FieldController::class, 'move'], 'user'],
    ['GET', $field . '/delete', [FieldController::class, 'deleteForm'], 'user'],
    ['POST', $field . '/delete', [FieldController::class, 'delete'], 'user'],

    ['GET', '/admin/people', [PeopleController::class, 'index'], 'user'],
    ['GET', '/admin/people/new', [PeopleController::class, 'newForm'], 'user'],
    ['POST', '/admin/people', [PeopleController::class, 'create'], 'user'],
    ['GET', '/admin/people/{person}', [PeopleController::class, 'show'], 'user'],
    ['POST', '/admin/people/{person}', [PeopleController::class, 'update'], 'user'],
    ['POST', '/admin/people/{person}/password', [PeopleController::class, 'password'], 'user'],
    ['POST', '/admin/people/{person}/projects', [PeopleController::class, 'access'], 'user'],
    ['GET', '/admin/people/{person}/deactivate', [PeopleController::class, 'deactivateForm'], 'user'],
    ['POST', '/admin/people/{person}/deactivate', [PeopleController::class, 'deactivate'], 'user'],
    ['POST', '/admin/people/{person}/reactivate', [PeopleController::class, 'reactivate'], 'user'],
];
