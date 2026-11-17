<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Command-line password reset (M1 spec section 10.6), for an Admin who
 * has forgotten their password and has shell access to the server:
 *
 *     php tools/admin/reset-password.php <username> [--data-dir=<path>]
 *
 * Finds the data directory as the application does (config/local.php
 * `data_dir`, then ../tiffindb-data, then data/), or uses --data-dir.
 * Reads the new password twice from standard input, with echo turned off
 * when standard input is a terminal that allows it. Applies the password
 * rules, stores the password (no change required at next sign-in), ends
 * every session of the person, and records `people.password_set` with no
 * actor. Exit 0 on success, 1 on any problem, 2 when not run from the
 * command line. Messages never name absolute paths.
 */

declare(strict_types=1);

namespace TiffinDB\Tools\Admin;

use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SecureRandom;
use TiffinDB\Core\Storage\SystemClock;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;

if (PHP_SAPI !== 'cli') {
    exit(2);
}

/** Plain English for the password problems PasswordPolicy reports. */
const PROBLEM_TEXT = [
    'problem.password.too_short' => 'The password must have at least 10 characters.',
    'problem.password.too_long' => 'The password is too long. Use at most 72 bytes.',
    'problem.password.same_as_username' => 'Do not use the username as the password.',
    'problem.text.control' => 'The password holds characters that are not allowed.',
];

function say(string $line): void
{
    fwrite(STDERR, $line . "\n");
}

/** @param list<string> $arguments @return array{0: ?string, 1: ?string} username, data directory */
function parseArguments(array $arguments): array
{
    $username = null;
    $dataDir = null;
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--data-dir=')) {
            $dataDir = substr($argument, strlen('--data-dir='));
        } elseif (str_starts_with($argument, '--') || $username !== null) {
            return [null, null];
        } else {
            $username = $argument;
        }
    }

    return [$username, $dataDir];
}

function findDataDirectory(string $appRoot, ?string $given): ?DataDirectory
{
    if ($given !== null) {
        if ($given === '' || str_contains($given, "\0")) {
            return null;
        }
        if (!str_starts_with($given, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }
            $given = $cwd . '/' . $given;
        }
        $data = new DataDirectory($given, 'the folder given with --data-dir');

        return is_file($data->systemDatabase()) ? $data : null;
    }
    $configured = null;
    $local = $appRoot . '/config/local.php';
    if (is_file($local)) {
        $config = require $local;
        if (is_array($config) && is_string($config['data_dir'] ?? null)) {
            $configured = $config['data_dir'];
        }
    }

    return DataDirectory::locate($appRoot, $configured);
}

/** Turns terminal echo on or off; false when that is not possible. */
function setEcho(bool $on): bool
{
    if (!function_exists('proc_open') || !stream_isatty(STDIN)) {
        return false;
    }
    $process = proc_open(['stty', $on ? 'echo' : '-echo'], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0;
}

/** One line from standard input without its line ending; passwords are never trimmed. */
function readLine(): ?string
{
    $line = fgets(STDIN);
    if ($line === false) {
        return null;
    }
    if (str_ends_with($line, "\r\n")) {
        return substr($line, 0, -2);
    }

    return str_ends_with($line, "\n") ? substr($line, 0, -1) : $line;
}

/** @param list<string> $arguments */
function main(array $arguments): int
{
    [$username, $given] = parseArguments($arguments);
    if ($username === null) {
        say('Usage: php tools/admin/reset-password.php <username> [--data-dir=<path>]');

        return 1;
    }
    $appRoot = dirname(__DIR__, 2);
    $autoload = $appRoot . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        say('The dependencies are not installed. Run composer install first.');

        return 1;
    }
    require_once $autoload;

    try {
        $data = findDataDirectory($appRoot, $given);
    } catch (\Throwable) {
        $data = null;
    }
    if ($data === null) {
        say('No TiffinDB data folder was found. Give its location with --data-dir=<path>.');

        return 1;
    }

    $system = null;
    try {
        $system = SystemDatabase::open($data);
        $clock = new SystemClock();
        $random = new SecureRandom();
        $users = new Users($system, new Keys($random), $clock);
        $person = $users->byUsername($username);
        if ($person === null) {
            say('No person has that username.');

            return 1;
        }

        $interactive = stream_isatty(STDIN);
        $hidden = $interactive && setEcho(false);
        if ($interactive && !$hidden) {
            say('Note: the password will be visible as you type it.');
        }
        try {
            if ($interactive) {
                fwrite(STDERR, 'New password: ');
            }
            $first = readLine();
            if ($interactive) {
                fwrite(STDERR, "\nThe same password again: ");
            }
            $second = readLine();
            if ($interactive) {
                fwrite(STDERR, "\n");
            }
        } finally {
            if ($hidden) {
                setEcho(true);
            }
        }
        if ($first === null || $second === null) {
            say('Two passwords were expected on standard input.');

            return 1;
        }
        if (!hash_equals($first, $second)) {
            say('The two passwords are not the same.');

            return 1;
        }
        $problems = PasswordPolicy::problems($first, $person->username);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                /** @var Problem $problem */
                say(PROBLEM_TEXT[$problem->key] ?? 'The password does not meet the rules.');
            }

            return 1;
        }

        $hash = (new PasswordHasher())->hash($first);
        $sessions = new Sessions($system, $clock, $random);
        $id = $person->id;
        $system->db()->write(static function () use ($users, $sessions, $id, $hash): void {
            if ($users->byId($id) === null) {
                throw new \RuntimeException('The person is gone.');
            }
            $users->setPassword($id, $hash, false);
            $sessions->endAllFor($id);
        });
        (new AuditLog($system, $clock))->record(AuditAction::PEOPLE_PASSWORD_SET, null, null, 'user', $person->key);
        say('The password has been reset, and every session of that person has ended.');

        return 0;
    } catch (\Throwable $error) {
        say('The password could not be reset (' . $error::class . ').');

        return 1;
    } finally {
        $system?->db()->close();
    }
}

exit(main(array_slice($argv, 1)));
