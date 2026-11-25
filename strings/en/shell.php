<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Shell, shared form, and shared notice strings (M0 spec section 9.4;
 * M1 spec part 2 section 5.2; M2 spec part 2 section 6.3: the Display
 * link, the project search, and the sidebar; M3 spec part 2 section 4:
 * the Plugins link and the sidebar's Data group; M4 spec part 2 section 7:
 * the sidebar's Collections group; M7 screens spec section 7: the Activity
 * tab and the About link).
 */

declare(strict_types=1);

return [
    'app.name' => 'TiffinDB',

    'shell.skip' => 'Skip to main content',
    'shell.brand.label' => 'TiffinDB, all projects',
    'shell.title' => '{title} - TiffinDB',
    'shell.footer.licence' => 'TiffinDB, free software under AGPL-3.0-or-later',
    'shell.footer.source' => 'Source code',
    'shell.footer.about' => 'About TiffinDB',
    'shell.nav.main' => 'Main',
    'shell.nav.account' => 'Account',
    'shell.nav.projects' => 'Projects',
    'shell.nav.people' => 'People',
    'shell.nav.plugins' => 'Plugins',
    'shell.nav.activity' => 'Activity',
    'shell.account.label' => '{name}, change your password',
    'shell.sign_out' => 'Sign out',
    'shell.breadcrumb' => 'Where you are',
    'shell.nav.display' => 'Display',
    'shell.search.label' => 'Search all tables in {project}',
    'shell.search.placeholder' => 'Search {project}',

    'sidebar.label' => 'Project',
    'sidebar.project' => 'Project',
    'sidebar.switch' => 'Switch project',
    'sidebar.tables' => 'Tables',
    'sidebar.schema' => 'Tables and fields',
    'sidebar.trash' => 'In the trash',
    'sidebar.data' => 'Data',
    'sidebar.import' => 'Import a file',
    'sidebar.export' => 'Export',
    'sidebar.collections' => 'Collections',
    'sidebar.new_collection' => 'New collection',

    'count.problems.one' => 'There is a problem',
    'count.problems.many' => 'There are {count} problems',

    'form.errors.page_title' => 'Error: {title}',
    'form.show_password' => 'Show password',
    'form.show_passwords' => 'Show passwords',
    'form.cancel' => 'Cancel',
    'form.optional' => '(optional)',

    'notice.signed_out' => 'You have signed out.',
    'notice.password_changed' => 'Your password has been changed.',
];
