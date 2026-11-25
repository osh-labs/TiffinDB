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

    // Project settings: more for this project (docs/design/m7/components.md section 4).
    'projects.settings.more' => 'More for this project',
    'projects.settings.snapshots' => 'Snapshots',
    'projects.settings.snapshots_help' => 'Saved copies of the project, and going back to one.',
    'projects.settings.delete' => 'Delete this project',
    'projects.settings.delete_help' => 'Removes the project, its files, and its snapshots for good.',

    // Delete a project (docs/design/m7/project-delete.md).
    'projects.delete.crumb' => 'Delete',
    'projects.delete.title' => 'Delete {project}?',
    'projects.delete.lead' => 'This deletes the project for good. It cannot be undone in TiffinDB.',
    'projects.delete.records' => 'Records',
    'projects.delete.records_value' => '{count|# record|# records}',
    'projects.delete.trashed' => 'In the trash',
    'projects.delete.files' => 'Stored files',
    'projects.delete.files_value' => '{count|# file|# files}, {size}',
    'projects.delete.snapshots' => 'Snapshots',
    'projects.delete.snapshots_value' => '{count|# snapshot|# snapshots}',
    'projects.delete.members' => 'Shared with',
    'projects.delete.members_value' => '{count|# Member|# Members}',
    'projects.delete.body' => 'Its records, tables, forms, collections, stored files, and snapshots are removed from the server. The people it was shared with lose access. The activity log keeps a line saying it was deleted.',
    'projects.delete.folder_missing' => 'This project\'s folder is not in the data folder, so there is nothing to remove from the server. Deleting removes the project from the list and from everyone\'s access.',
    'projects.delete.archive' => 'Keep a copy first: download a project archive from the export page. A project archive can be imported again later.',
    'projects.delete.archive_link' => 'Go to Export',
    'projects.delete.name_label' => 'Type the project\'s name to confirm',
    'projects.delete.name_help' => 'Type {project}.',
    'projects.delete.submit' => 'Delete for good',
    'projects.delete.changed' => 'The project changed while you were looking. Check the new numbers.',
    'notice.project_deleted' => '{name} has been deleted.',
];
