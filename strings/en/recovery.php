<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Admin recovery page strings (docs/design/m7/recovery.md section 3). The
 * sign-in page's link and notice are in account.php.
 */

declare(strict_types=1);

return [
    'recovery.title' => 'Get back into an Admin account',
    'recovery.lead' => 'If no Admin can sign in, someone who can open the files on the server can let you set a new Admin password. This works for Admin accounts only. Members: ask an Admin to set a new password for you.',
    'recovery.step1.title' => '1. Ask for a code',
    'recovery.step1.body' => 'TiffinDB writes a one-time code into a file named recovery-code.txt in its data folder. Open that file with your host\'s file manager, an SFTP program, or a shell. The code works once, for 30 minutes.',
    'recovery.start' => 'Write a code',
    'recovery.started' => 'If recovery is allowed on this server, a code is now in recovery-code.txt in the data folder. If a code was already waiting there, it is still the one to use.',
    'recovery.step2.title' => '2. Set a new password',
    'recovery.username' => 'Admin username',
    'recovery.code' => 'Code from the file',
    'recovery.code_help' => '16 letters and digits. Dashes and spaces do not matter.',
    'recovery.password' => 'New password',
    'recovery.password_again' => 'New password again',
    'recovery.submit' => 'Change the password',
    'recovery.back' => 'Back to sign in',
    'recovery.other_ways' => 'Other ways back in: another Admin can set your password on the People page; with a shell, the host documentation describes a command that does it.',
    'recovery.wait' => 'Too many tries. Wait {time}, then try again.',
];
