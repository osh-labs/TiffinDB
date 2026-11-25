<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Sign-in and own-password strings (docs/design/m1/sign-in-and-projects.md
 * sections 2, 4, and 5); the recovery link and notice (docs/design/m7/
 * recovery.md section 2).
 */

declare(strict_types=1);

return [
    'signin.title' => 'Sign in',
    'signin.username' => 'Username',
    'signin.password' => 'Password',
    'signin.submit' => 'Sign in',
    'signin.reset_note' => 'Forgotten your password? Ask the person who looks after this TiffinDB to reset it.',
    'signin.error.invalid' => 'That username and password do not match. Check them and try again.',
    'signin.recover' => 'Locked out of the Admin account?',
    'notice.recovered' => 'The password has been changed. Sign in with the new password.',
    'account.password.title' => 'Change your password',
    'account.password.lead' => 'Choose a password that only you know.',
    'account.password.forced' => 'Before you go on, choose your own password. The one you were given was only for signing in the first time.',
    'account.password.current' => 'Current password',
    'account.password.new' => 'New password',
    'account.password.new_help' => 'At least 10 characters. A few unrelated words make a good password.',
    'account.password.repeat' => 'Type the new password again',
    'account.password.mismatch' => 'The two new passwords are different.',
    'account.password.submit' => 'Change password',
];
