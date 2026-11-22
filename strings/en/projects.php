<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Project list and new project strings
 * (docs/design/m1/sign-in-and-projects.md section 5;
 * docs/design/m1/new-project.md section 4).
 */

declare(strict_types=1);

return [
    'projects.title' => 'Your projects',
    'projects.lead' => 'A project is one database: its tables, forms, and files.',
    'projects.new' => 'New project',
    'projects.import_archive' => 'Import a project archive',
    'projects.crumb' => 'Projects',
    'projects.fact.tables' => 'Tables',
    'projects.fact.records' => 'Records',
    'projects.fact.changed' => 'Last changed',
    'projects.fact.shared' => 'Shared with',
    'projects.no_tables' => 'None yet',
    'projects.never_changed' => 'Not yet',
    'projects.shared.none' => 'Admins only',
    'projects.open' => 'Open',
    'projects.open.label' => 'Open {name}',
    'projects.sharing' => 'Sharing',
    'projects.sharing.label' => 'Sharing for {name}',
    'projects.unavailable' => 'TiffinDB cannot open this project\'s file. The person who looks after this TiffinDB can check the data folder.',
    'projects.empty.admin' => 'There are no projects yet. Select New project to make the first one.',
    'projects.empty.member' => 'No projects have been shared with you yet. Ask the person who looks after this TiffinDB to share one with you.',
    'projects.admin_note' => 'Signed in as an Admin. Members see only the projects shared with them, and do not see New project, Sharing, or People.',
    'count.people.one' => '1 person',
    'count.people.many' => '{count} people',

    'projects.new.title' => 'New project',
    'projects.new.lead' => 'A project is one database. Add tables to it, then share it with the people who will use it.',
    'projects.field.name' => 'Project name',
    'projects.field.name_help' => 'For example Home Inventory or Recipes.',
    'projects.field.first_table' => 'First table',
    'projects.field.first_table_help' => 'For example Items. TiffinDB gives it a Name field to start with. Leave this empty to add tables later.',
    'projects.new.submit' => 'Create project',
    'notice.project_created' => '{name} has been created.',
    'notice.project_created_table' => '{name} has been created, with the table {table}.',

    // Project settings: the stored files (ADR 0018 decision 3; M4 spec part 2 section 4).
    'projects.settings.files' => 'This project stores {count|# file|# files}, {size} in all.',
];
