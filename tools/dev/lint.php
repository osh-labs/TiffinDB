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
 * The excluded top-level directories must stay the same as
 * TiffinDB\Tests\Support\RepoFiles::EXCLUDED_DIRECTORIES.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$excluded = ['.git', 'vendor', 'node_modules', 'data', 'local', '.phpunit.cache'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($root, $excluded): bool {
            if (dirname($file->getPathname()) === $root) {
                return !in_array($file->getFilename(), $excluded, true);
            }
            return true;
        },
    ),
);

$files = [];
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
}
sort($files, SORT_STRING);

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
