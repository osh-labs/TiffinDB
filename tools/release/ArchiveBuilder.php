<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tools\Release;

use RuntimeException;
use ZipArchive;

/**
 * Writes the release archive (M7 release spec section 3.4, steps 3 and 5 to
 * 9; ADR 0021 decision 3). Plain PHP with no dependency on src/ or vendor/.
 *
 * Every failure is a RuntimeException whose message is one plain line with
 * no absolute path; no `.part` file is left behind.
 */
final class ArchiveBuilder
{
    /** 1980-01-01 00:00:00 UTC, the earliest time a zip entry can hold. */
    public const MTIME = 315532800;
    public const FILE_MODE = 0100644;
    public const FOLDER_MODE = 040755;
    public const MANIFEST = 'MANIFEST.txt';

    public function __construct(
        private readonly string $root,
        private readonly string $vendor,
    ) {
    }

    /**
     * Step 3: the tracked files the include list selects, plus the filtered
     * production vendor files, as archive path => source file.
     *
     * @param list<string> $tracked
     * @return array<string, string>
     */
    public function select(array $tracked, string $record): array
    {
        $entries = [];
        foreach (ReleaseRules::select($tracked) as $path) {
            $entries[$path] = $this->root . '/' . $path;
        }
        foreach (ReleaseRules::vendorFiles($this->vendor, $record) as $path) {
            $entries[$path] = $this->vendor . '/' . substr($path, strlen('vendor/'));
        }

        return $entries;
    }

    /**
     * Steps 5 to 9: read every file, sort, write the manifest and the zip,
     * then the `.sha256` file.
     *
     * @param array<string, string> $entries archive path => source file
     * @return array{archive: string, sha256: string, files: int, bytes: int}
     */
    public static function write(array $entries, string $out, string $version): array
    {
        $top = 'tiffindb-' . $version;
        $archive = $top . '.zip';
        $final = $out . '/' . $archive;
        $part = $final . '.part';

        if (file_exists($final) || is_link($final)) {
            throw new RuntimeException("{$archive} already exists in the output folder; it is never overwritten");
        }

        // Step 5: regular files only, read as bytes.
        $contents = [];
        foreach ($entries as $path => $source) {
            $path = (string) $path;
            if ($path === self::MANIFEST) {
                throw new RuntimeException(self::MANIFEST . ' is generated and cannot be an input file');
            }
            if (is_link($source)) {
                throw new RuntimeException(ReleaseRules::printable($path) . ' is a symbolic link');
            }
            if (!is_file($source)) {
                throw new RuntimeException(ReleaseRules::printable($path) . ' is not a regular file');
            }
            $bytes = file_get_contents($source);
            if ($bytes === false) {
                throw new RuntimeException(ReleaseRules::printable($path) . ' cannot be read');
            }
            $contents[$path] = $bytes;
        }

        // Step 6: byte order.
        $paths = array_keys($contents);
        usort($paths, 'strcmp');

        // Step 7: the manifest, in the same order, not listing itself.
        $manifest = '';
        foreach ($paths as $path) {
            $manifest .= hash('sha256', $contents[$path]) . '  ' . $path . "\n";
        }

        $folders = [$top . '/' => true];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            array_pop($parts);
            $prefix = $top . '/';
            foreach ($parts as $segment) {
                $prefix .= $segment . '/';
                $folders[$prefix] = true;
            }
        }
        $folders = array_keys($folders);
        usort($folders, 'strcmp');

        // Step 8.
        putenv('TZ=UTC');
        if (file_exists($part) || is_link($part)) {
            if (!unlink($part)) {
                throw new RuntimeException("A stale {$archive}.part cannot be removed");
            }
        }
        $zip = new ZipArchive();
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("{$archive}.part cannot be created in the output folder");
        }

        try {
            foreach ($folders as $folder) {
                self::check($zip->addEmptyDir(rtrim($folder, '/')), $folder);
                self::fix($zip, $folder, self::FOLDER_MODE);
            }
            foreach ($paths as $path) {
                self::check($zip->addFromString($top . '/' . $path, $contents[$path]), $path);
                self::fix($zip, $top . '/' . $path, self::FILE_MODE);
            }
            self::check($zip->addFromString($top . '/' . self::MANIFEST, $manifest), self::MANIFEST);
            self::fix($zip, $top . '/' . self::MANIFEST, self::FILE_MODE);
        } catch (RuntimeException $e) {
            // Nothing is on disk until close(); drop the pending entries.
            $zip->unchangeAll();
            $zip->close();
            self::discard($part);
            throw $e;
        }
        if (!$zip->close()) {
            self::discard($part);
            throw new RuntimeException("{$archive}.part cannot be written");
        }

        // Step 9: no overwrite (a hard link fails when the target exists).
        $hash = hash_file('sha256', $part);
        $bytes = filesize($part);
        if ($hash === false || $bytes === false || !link($part, $final)) {
            self::discard($part);
            throw new RuntimeException("{$archive} cannot be put in place (it may already exist)");
        }
        self::discard($part);
        if (file_put_contents($final . '.sha256', $hash . '  ' . $archive . "\n") === false) {
            throw new RuntimeException("{$archive}.sha256 cannot be written");
        }

        return ['archive' => $archive, 'sha256' => $hash, 'files' => count($paths) + 1, 'bytes' => $bytes];
    }

    private static function check(bool $ok, string $name): void
    {
        if (!$ok) {
            throw new RuntimeException(ReleaseRules::printable($name) . ' cannot be added to the archive');
        }
    }

    private static function fix(ZipArchive $zip, string $name, int $mode): void
    {
        self::check(
            $zip->setMtimeName($name, self::MTIME)
                && $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16)
                && $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 9),
            $name,
        );
    }

    private static function discard(string $part): void
    {
        if (file_exists($part) || is_link($part)) {
            unlink($part);
        }
    }
}
