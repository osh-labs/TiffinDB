<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Installer strings (docs/design/m1/installer.md; M1 spec part 2
 * section 5.1). Keys under `setup.check.` are partly built at run time
 * from the installer's requirement report. `setup.code_location` and
 * `setup.code_location.configured` are the keys Installer::setupCodeLocation()
 * returns; they hold the whole help sentence of the setup-code field.
 */

declare(strict_types=1);

return [
    'setup.title' => 'Set up TiffinDB',
    'setup.lead' => 'Three short steps. Nothing is saved until step 2 makes the data folder.',
    'setup.steps.label' => 'Setup steps',
    'setup.step.number' => 'Step {number}',
    'setup.step.done' => 'Step {number}, done',
    'setup.step.now' => 'Step {number}, now',
    'setup.step.1' => 'Check this server',
    'setup.step.2' => 'Make the data folder safe',
    'setup.step.3' => 'Create the first Admin',

    'setup.result.ok' => 'Ready',
    'setup.result.warning' => 'Works, with a note',
    'setup.result.failed' => 'Needs fixing',

    // Step 1.
    'setup.check.title' => 'Step 1: Check this server',
    'setup.check.intro' => 'TiffinDB has checked what this web server offers.',
    'setup.check.caption' => 'Server checks',
    'setup.check.col.check' => 'Check',
    'setup.check.col.result' => 'Result',
    'setup.check.col.meaning' => 'What this means',
    'setup.check.next' => 'Next, TiffinDB makes its data folder and checks that it cannot be opened from the web.',
    'setup.check.continue' => 'Make the data folder',
    'setup.check.fix' => 'Fix the checks marked Needs fixing, then check again. Your host\'s control panel or support can help with the PHP settings.',
    'setup.check.again' => 'Check again',

    'setup.check.php' => 'PHP version',
    'setup.check.php.ok' => 'PHP {version}. TiffinDB needs 8.3 or later.',
    'setup.check.php.failed' => 'PHP {version} is too old. Ask your host to switch this site to PHP 8.3 or later.',
    'setup.check.sqlite' => 'SQLite database support',
    'setup.check.sqlite.ok' => 'SQLite {version} is available.',
    'setup.check.sqlite.missing' => 'PHP\'s SQLite support (pdo_sqlite) is not turned on. Ask your host to turn it on.',
    'setup.check.sqlite.old' => 'SQLite {version} is too old. TiffinDB needs 3.35.5 or later.',
    'setup.check.zip' => 'Zip archives',
    'setup.check.zip.ok' => 'Available. Needed to save and bring in whole projects.',
    'setup.check.zip.failed' => 'PHP\'s zip support is not turned on. Ask your host to turn it on.',
    'setup.check.xmlreader' => 'Reading spreadsheet files',
    'setup.check.xmlreader.ok' => 'Available. Needed to bring in XLSX spreadsheets.',
    'setup.check.xmlreader.failed' => 'PHP\'s XMLReader support is not turned on. Ask your host to turn it on.',
    'setup.check.random' => 'Secure random numbers',
    'setup.check.random.ok' => 'Available.',
    'setup.check.random.failed' => 'PHP cannot make secure random numbers on this server. TiffinDB cannot run safely without them.',
    'setup.check.passwords' => 'Password protection',
    'setup.check.passwords.argon2id' => 'Passwords will be stored with Argon2id.',
    'setup.check.passwords.bcrypt' => 'Passwords will be stored with bcrypt. This is safe. Argon2id is better and is used where PHP offers it.',
    'setup.check.https' => 'Secure connection (HTTPS)',
    'setup.check.https.ok' => 'This page was loaded over HTTPS.',
    'setup.check.https.warning' => 'This page was not loaded over HTTPS, so passwords could be read by others on the network. Ask your host to turn on HTTPS, then open this page with https:// at the start.',
    'setup.check.data' => 'A place for the data folder',
    'setup.check.data.outside' => 'TiffinDB will keep its data in {label}, outside the web site.',
    'setup.check.data.in-tree' => 'TiffinDB will keep its data in the folder {label} inside the TiffinDB folder, protected by a server rule. Step 2 tests that the rule works.',
    'setup.check.data.failed' => 'TiffinDB cannot make its data folder at {label}. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php.',
    'setup.check.data.configured-outside' => 'TiffinDB will keep its data in the folder set as data_dir in config/local.php, outside the web site.',
    'setup.check.data.configured-in-tree' => 'TiffinDB will keep its data in the folder set as data_dir in config/local.php, which is inside the web site and protected by a server rule. Step 2 tests that the rule works.',
    'setup.check.data.configured-failed' => 'TiffinDB cannot make its data folder at the path set as data_dir in config/local.php. Check the path, and let the web server write to that folder or to the folder that holds it.',

    // Step 2.
    'setup.data.exposed.title' => 'Your data folder can be opened from the web',
    'setup.data.exposed.body' => 'Anyone could download TiffinDB\'s data from {label}. Setup stops here until this is fixed.',
    'setup.data.unclear.title' => 'TiffinDB could not test the data folder',
    'setup.data.unclear.body' => 'TiffinDB asked this web server for a test file in {label}, to make sure the folder cannot be opened from the web, but did not get a clear answer. To be safe, setup stops here.',
    'setup.data.failed.title' => 'TiffinDB could not make its data folder',
    'setup.data.no-place' => 'There is no folder TiffinDB can write to. Let the web server write to the folder that holds the TiffinDB folder, or set data_dir in config/local.php.',
    'setup.data.not-empty' => 'TiffinDB found files that it did not put there in {label}. Empty that folder, or set data_dir in config/local.php to another folder.',
    'setup.data.test_again' => 'Test again',
    'setup.fix.title' => 'How to fix it',
    'setup.fix.root' => 'Best: make the public folder inside the TiffinDB folder the root of your web site, so that the data folder is outside it. Hosting control panels call this the document root or web directory.',
    'setup.fix.htaccess' => 'Or: make sure the web server reads .htaccess files in the TiffinDB folder. Ask your host if you are not sure.',
    'setup.fix.local' => 'Or: put the data folder outside the web site, and write its full path as data_dir in config/local.php.',

    // Step 3.
    'setup.data.ready' => 'Step 2 is done: the data folder is ready, and it cannot be opened from the web.',
    'setup.admin.title' => 'Step 3: Create the first Admin',
    'setup.admin.intro' => 'The first Admin can add other people, create projects, share them, and change tables and fields.',
    'setup.admin.code' => 'Setup code',
    'setup.code_location' => 'To show that you look after this server, open the file {path} with your hosting file manager or FTP program, and copy the code from it.',
    'setup.code_location.configured' => 'To show that you look after this server, open the file {file} in the data folder set as data_dir in config/local.php with your hosting file manager or FTP program, and copy the code from it.',
    'setup.admin.name' => 'Your name',
    'setup.admin.name_help' => 'Shown to others, for example on the project list.',
    'setup.admin.username' => 'Username',
    'setup.admin.username_help' => 'Used to sign in. Letters, numbers, dots, dashes, and underscores.',
    'setup.admin.password' => 'Password',
    'setup.admin.password_help' => 'At least 10 characters. A few unrelated words make a good password.',
    'setup.admin.repeat' => 'Type the password again',
    'setup.admin.mismatch' => 'The two passwords are different.',
    'setup.admin.submit' => 'Create Admin and finish',

    'notice.setup.done' => 'TiffinDB is ready. Select New project to make your first project.',
];
