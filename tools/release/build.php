<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Builds the release archive (M7 release spec section 3.4; ADR 0021).
 *
 *   php tools/release/build.php --vendor=<dir> --out=<dir> [--test-build] [--allow-dirty]
 *
 * Exit 0 on success, 1 on a refusal (one plain line per problem on standard
 * error, no absolute path), 2 when not run from the command line or on an
 * unknown or missing option. Downloads nothing, runs no shell, and never
 * writes inside the repository. Runs without vendor/autoload.php.
 */

declare(strict_types=1);

namespace TiffinDB\Tools\Release;

use RuntimeException;

if (PHP_SAPI !== 'cli') {
    exit(2);
}

require_once __DIR__ . '/ReleaseRules.php';
require_once __DIR__ . '/ArchiveBuilder.php';

const USAGE = 'Usage: php tools/release/build.php --vendor=<dir> --out=<dir> [--test-build] [--allow-dirty]';

/** @param list<string> $lines */
function refuse(array $lines): never
{
    foreach ($lines as $line) {
        fwrite(STDERR, $line . "\n");
    }
    exit(1);
}

/**
 * Runs git with an argument array (no shell) from the repository root.
 *
 * @param list<string> $arguments
 */
function git(string $root, array $arguments): string
{
    $process = proc_open(
        ['git', '-c', 'core.quotePath=off', ...$arguments],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git cannot be run');
    }
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $output === false) {
        throw new RuntimeException('git ' . $arguments[0] . ' failed (is this a git checkout?)');
    }

    return $output;
}

/** @return list<string> */
function nulList(string $output): array
{
    return array_values(array_filter(explode("\0", $output), static fn (string $s): bool => $s !== ''));
}

// ------------------------------------------------------------------ options

$options = ['vendor' => null, 'out' => null, 'test-build' => false, 'allow-dirty' => false];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(vendor|out)=(.+)\z/s', $argument, $m) === 1) {
        $options[$m[1]] = $m[2];
    } elseif ($argument === '--test-build' || $argument === '--allow-dirty') {
        $options[substr($argument, 2)] = true;
    } else {
        fwrite(STDERR, 'Unknown option: ' . ReleaseRules::printable($argument) . "\n" . USAGE . "\n");
        exit(2);
    }
}
if ($options['vendor'] === null || $options['out'] === null) {
    fwrite(STDERR, USAGE . "\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$rootReal = realpath($root);
$vendor = realpath((string) $options['vendor']);
if ($rootReal === false) {
    refuse(['The repository root cannot be resolved.']);
}
if ($vendor === false || !is_dir($vendor)) {
    refuse(['The --vendor folder does not exist.']);
}

// The output folder: outside the repository, or in a git-ignored folder of
// it (such as build/, which CI uses); never where a tracked file could be.
$out = (string) $options['out'];
$absolute = str_starts_with($out, '/') ? $out : (string) getcwd() . '/' . $out;
$segments = [];
foreach (explode('/', $absolute) as $segment) {
    if ($segment === '..') {
        array_pop($segments);
    } elseif ($segment !== '' && $segment !== '.') {
        $segments[] = $segment;
    }
}
$existing = $segments;
$missing = [];
while ($existing !== [] && !is_dir('/' . implode('/', $existing))) {
    array_unshift($missing, array_pop($existing));
}
$resolved = realpath('/' . implode('/', $existing));
if ($resolved === false) {
    refuse(['The --out folder cannot be resolved.']);
}
$resolved = rtrim($resolved, '/') . ($missing === [] ? '' : '/' . implode('/', $missing));
if ($resolved === $rootReal) {
    refuse(['The --out folder must not be the repository root.']);
}
if (str_starts_with($resolved, $rootReal . '/')) {
    $relative = substr($resolved, strlen($rootReal) + 1) . '/';
    $check = proc_open(['git', 'check-ignore', '-q', '--', $relative], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $rootReal);
    if (!is_resource($check) || proc_close($check) !== 0) {
        refuse(['The --out folder must be outside the repository or in a git-ignored folder of it (the tool never writes where a tracked file could be).']);
    }
}
if (!is_dir($resolved) && !mkdir($resolved, 0755, true)) {
    refuse(['The --out folder cannot be created.']);
}
$outReal = (string) realpath($resolved);

try {
    // Step 1: the version.
    $config = (static fn (string $path): mixed => include $path)($root . '/config/app.php');
    $version = is_array($config) && is_string($config['version'] ?? null) ? $config['version'] : '';
    $pattern = $options['test-build']
        ? '/^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[1-9][0-9]*|-dev)?\z/'
        : '/^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[1-9][0-9]*)?\z/';
    if (preg_match($pattern, $version) !== 1) {
        refuse([
            str_ends_with($version, '-dev') && !$options['test-build']
                ? "config/app.php version {$version} is a development version; use --test-build for a test archive"
                : 'config/app.php version ' . ReleaseRules::printable($version) . ' is not a release version (x.y.z or x.y.z-rc.n)',
        ]);
    }

    // Step 2: tracked files, and a clean tree for every included path.
    $tracked = nulList(git($root, ['ls-files', '-z']));
    $dirty = [];
    $status = nulList(git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all']));
    for ($i = 0; $i < count($status); $i++) {
        $entry = $status[$i];
        $paths = [substr($entry, 3)];
        if ($entry[0] === 'R' || $entry[0] === 'C') {
            $paths[] = $status[++$i] ?? '';
        }
        foreach ($paths as $path) {
            if (ReleaseRules::included($path)) {
                $dirty[] = $path;
            }
        }
    }
    if ($dirty !== [] && !$options['allow-dirty']) {
        refuse(array_map(
            static fn (string $p): string => 'Uncommitted change to an included path: ' . ReleaseRules::printable($p),
            $dirty,
        ));
    }
    if ($dirty !== []) {
        fwrite(STDERR, "Warning: the tree has uncommitted changes to included paths; this archive is not reproducible from a commit.\n");
    }

    // Step 3: the archive file list.
    $recordFile = $root . '/' . ReleaseRules::DEPENDENCIES;
    $record = is_file($recordFile) && !is_link($recordFile) ? (string) file_get_contents($recordFile) : '';
    $vendorProblems = ReleaseRules::vendorProblems($vendor, $record);
    if ($vendorProblems !== []) {
        refuse($vendorProblems);
    }
    $builder = new ArchiveBuilder($root, $vendor);
    $entries = $builder->select($tracked, $record);

    // Step 4: the release rules.
    $violations = ReleaseRules::violations(array_keys($entries), $root, $vendor);
    if ($violations !== []) {
        refuse($violations);
    }

    // Steps 5 to 9.
    $result = ArchiveBuilder::write($entries, $outReal, $version);
} catch (RuntimeException $e) {
    refuse([str_replace([$rootReal, $vendor, $outReal], ['<repository>', '<vendor>', '<out>'], $e->getMessage())]);
}

// Step 10.
printf(
    "%s  %s\nFiles: %d\nBytes: %d\nPHP %s, libzip %s, zlib %s\n",
    $result['sha256'],
    $result['archive'],
    $result['files'],
    $result['bytes'],
    PHP_VERSION,
    \ZipArchive::LIBZIP_VERSION,
    ZLIB_VERSION,
);
exit(0);
