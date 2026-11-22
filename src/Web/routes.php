<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * The route table (ADR 0008 decision 2; M1 spec part 2 section 2.3): a
 * list of [method, pattern, [ControllerClass, 'method'], access]. First
 * match wins, so literal segments such as `new` come before placeholders.
 * M2 adds the settings, search, list, and record routes (M2 spec part 2
 * section 2), M3 the import, export, archive, and plugins routes (M3 spec
 * part 2 section 3; their controllers arrive with M3-WP9 and WP10), M4
 * the file, collection, trash, link, and upload routes (M4 spec part 2
 * section 3; their controllers arrive with M4-WP6 to WP8), M5 the form
 * editor, source, download, upload, and reset routes (M5 spec part 2
 * section 2; their controllers arrive with M5-WP5 and WP6). `/files/{field}/new`
 * comes before `/files/{field}/{attachment}`.
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use TiffinDB\Web\Controller\AccountController;
use TiffinDB\Web\Controller\ArchiveController;
use TiffinDB\Web\Controller\CollectionController;
use TiffinDB\Web\Controller\ExportController;
use TiffinDB\Web\Controller\FieldController;
use TiffinDB\Web\Controller\FileController;
use TiffinDB\Web\Controller\FormController;
use TiffinDB\Web\Controller\FormSourceController;
use TiffinDB\Web\Controller\HomeController;
use TiffinDB\Web\Controller\ImportController;
use TiffinDB\Web\Controller\LinkController;
use TiffinDB\Web\Controller\PeopleController;
use TiffinDB\Web\Controller\PluginController;
use TiffinDB\Web\Controller\ProjectController;
use TiffinDB\Web\Controller\RecordController;
use TiffinDB\Web\Controller\RecordListController;
use TiffinDB\Web\Controller\SearchController;
use TiffinDB\Web\Controller\SettingsController;
use TiffinDB\Web\Controller\SetupController;
use TiffinDB\Web\Controller\SharingController;
use TiffinDB\Web\Controller\SignInController;
use TiffinDB\Web\Controller\TableController;
use TiffinDB\Web\Controller\TrashController;

$field = '/projects/{project}/tables/{table}/fields/{field}';
$table = '/projects/{project}/tables/{table}';
$import = '/projects/{project}/import';
$plugin = '/admin/plugins/{kind}/{plugin}';
$record = $table . '/records/{record}';
$collection = '/projects/{project}/collections/{collection}';
$trash = '/projects/{project}/trash';

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

    ['GET', '/settings', [SettingsController::class, 'form'], 'user'],
    ['POST', '/settings', [SettingsController::class, 'save'], 'user'],
    ['POST', '/settings/reset', [SettingsController::class, 'reset'], 'user'],

    ['GET', '/projects', [ProjectController::class, 'index'], 'user'],
    ['GET', '/projects/new', [ProjectController::class, 'newForm'], 'user'],
    ['POST', '/projects', [ProjectController::class, 'create'], 'user'],
    ['GET', '/projects/import', [ArchiveController::class, 'form'], 'user'],
    ['POST', '/projects/import', [ArchiveController::class, 'import'], 'user'],
    ['POST', '/projects/import/{job}', [ArchiveController::class, 'rename'], 'user'],
    ['GET', '/projects/{project}', [ProjectController::class, 'open'], 'user'],
    ['GET', '/projects/{project}/settings', [ProjectController::class, 'settingsForm'], 'user'],
    ['POST', '/projects/{project}/settings', [ProjectController::class, 'settings'], 'user'],
    ['GET', '/projects/{project}/sharing', [SharingController::class, 'form'], 'user'],
    ['POST', '/projects/{project}/sharing', [SharingController::class, 'save'], 'user'],
    ['GET', $import, [ImportController::class, 'start'], 'user'],
    ['POST', $import, [ImportController::class, 'upload'], 'user'],
    ['GET', $import . '/{job}', [ImportController::class, 'show'], 'user'],
    ['POST', $import . '/{job}', [ImportController::class, 'saveMapping'], 'user'],
    ['GET', $import . '/{job}/reading', [ImportController::class, 'readingForm'], 'user'],
    ['POST', $import . '/{job}/reading', [ImportController::class, 'reading'], 'user'],
    ['POST', $import . '/{job}/continue', [ImportController::class, 'continue'], 'user'],
    ['POST', $import . '/{job}/cancel', [ImportController::class, 'cancel'], 'user'],
    ['GET', $import . '/{job}/report', [ImportController::class, 'report'], 'user'],
    ['GET', '/projects/{project}/export', [ExportController::class, 'form'], 'user'],
    ['POST', '/projects/{project}/export', [ExportController::class, 'export'], 'user'],
    ['POST', '/projects/{project}/export/archive', [ExportController::class, 'archive'], 'user'],
    ['GET', '/projects/{project}/files/{attachment}', [FileController::class, 'serve'], 'user'],
    ['GET', $collection, [CollectionController::class, 'show'], 'user'],
    ['GET', $collection . '/edit', [CollectionController::class, 'editForm'], 'user'],
    ['POST', $collection . '/edit', [CollectionController::class, 'update'], 'user'],
    ['GET', $collection . '/delete', [CollectionController::class, 'deleteForm'], 'user'],
    ['POST', $collection . '/delete', [CollectionController::class, 'delete'], 'user'],
    ['GET', $trash, [TrashController::class, 'index'], 'user'],
    ['POST', $trash . '/restore', [TrashController::class, 'restore'], 'user'],
    ['POST', $trash . '/restore-all', [TrashController::class, 'restoreAll'], 'user'],
    ['GET', $trash . '/empty', [TrashController::class, 'emptyForm'], 'user'],
    ['POST', $trash . '/empty', [TrashController::class, 'empty'], 'user'],
    ['GET', '/projects/{project}/search', [SearchController::class, 'index'], 'user'],

    ['GET', '/projects/{project}/tables', [TableController::class, 'index'], 'user'],
    ['GET', '/projects/{project}/tables/new', [TableController::class, 'newForm'], 'user'],
    ['POST', '/projects/{project}/tables', [TableController::class, 'create'], 'user'],
    ['GET', '/projects/{project}/tables/{table}/fields', [TableController::class, 'fields'], 'user'],
    ['POST', '/projects/{project}/tables/{table}/settings', [TableController::class, 'update'], 'user'],
    ['GET', '/projects/{project}/tables/{table}/delete', [TableController::class, 'deleteForm'], 'user'],
    ['POST', '/projects/{project}/tables/{table}/delete', [TableController::class, 'delete'], 'user'],
    ['GET', $table . '/form', [FormController::class, 'edit'], 'user'],
    ['POST', $table . '/form', [FormController::class, 'change'], 'user'],
    ['GET', $table . '/form/reset', [FormController::class, 'resetForm'], 'user'],
    ['POST', $table . '/form/reset', [FormController::class, 'reset'], 'user'],
    ['GET', $table . '/form/source', [FormSourceController::class, 'show'], 'user'],
    ['POST', $table . '/form/source', [FormSourceController::class, 'submit'], 'user'],
    ['GET', $table . '/form/download', [FormSourceController::class, 'download'], 'user'],
    ['GET', $table . '/form/upload', [FormSourceController::class, 'uploadForm'], 'user'],
    ['POST', $table . '/form/upload', [FormSourceController::class, 'upload'], 'user'],

    ['GET', $table, [RecordListController::class, 'index'], 'user'],
    ['GET', $table . '/filter', [RecordListController::class, 'filter'], 'user'],
    ['GET', $table . '/collections/new', [CollectionController::class, 'newForm'], 'user'],
    ['POST', $table . '/collections', [CollectionController::class, 'create'], 'user'],
    ['GET', $table . '/records/new', [RecordController::class, 'newForm'], 'user'],
    ['POST', $table . '/records', [RecordController::class, 'create'], 'user'],
    ['GET', $table . '/records/{record}', [RecordController::class, 'show'], 'user'],
    ['POST', $table . '/records/{record}', [RecordController::class, 'update'], 'user'],
    ['POST', $table . '/records/{record}/trash', [RecordController::class, 'trash'], 'user'],
    ['POST', $table . '/records/{record}/restore', [RecordController::class, 'restore'], 'user'],
    ['GET', $record . '/links/{field}', [LinkController::class, 'picker'], 'user'],
    ['POST', $record . '/links/{field}/add', [LinkController::class, 'add'], 'user'],
    ['POST', $record . '/links/{field}/remove', [LinkController::class, 'remove'], 'user'],
    ['GET', $record . '/files/{field}/new', [FileController::class, 'uploadForm'], 'user'],
    ['POST', $record . '/files/{field}', [FileController::class, 'upload'], 'user'],
    ['GET', $record . '/files/{field}/{attachment}', [FileController::class, 'show'], 'user'],
    ['GET', $record . '/files/{field}/{attachment}/remove', [FileController::class, 'removeForm'], 'user'],
    ['POST', $record . '/files/{field}/{attachment}/remove', [FileController::class, 'remove'], 'user'],

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

    ['GET', '/admin/plugins', [PluginController::class, 'index'], 'user'],
    ['GET', $plugin, [PluginController::class, 'show'], 'user'],
    ['GET', $plugin . '/enable', [PluginController::class, 'enableForm'], 'user'],
    ['POST', $plugin . '/enable', [PluginController::class, 'enable'], 'user'],
    ['POST', $plugin . '/disable', [PluginController::class, 'disable'], 'user'],
];
