<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Syntax-checks every *.php file in the repository with `php -l`, using the
 * PHP binary that runs this script. Takes no arguments and makes no network
 * requests. Exits 0 only if at least one file was checked and every file
 * passed. Run it as `composer lint`.
 *
 * The file set is TiffinDB\Tests\Support\RepoFiles::withExtension(['php'])
 * (tracked and non-ignored files in a git checkout, otherwise a directory
 * walk; the same non-scanned directories either way), so the lint command
 * and the tests check the same files.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/Support/RepoFiles.php';

use TiffinDB\Tests\Support\RepoFiles;

$root = RepoFiles::root();
$files = RepoFiles::withExtension(['php']);

$lint = static function (string $path): ?string {
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        return 'Could not start the PHP binary.';
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? null : trim($output);
};

$failures = 0;
foreach ($files as $relative) {
    $error = $lint($root . DIRECTORY_SEPARATOR . $relative);
    if ($error !== null) {
        $failures++;
        fwrite(STDERR, "FAIL {$relative}\n{$error}\n");
    }
}

if ($files === []) {
    fwrite(STDERR, "Lint: no PHP files found.\n");
    exit(1);
}

printf("Lint: %d PHP file(s) checked, %d failed.\n", count($files), $failures);
exit($failures === 0 ? 0 : 1);
