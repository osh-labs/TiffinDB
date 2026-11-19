<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Sqlite;

/**
 * Writes a project archive (M3 spec section 11.1, ADR 0017 decision 1,
 * `docs/formats/tiffin.md` section 13): a consistent copy of the database
 * made with `VACUUM INTO` (never the live file, so no `-wal` or `-shm`
 * file is involved), the manifest, and every attachment file the copy
 * lists and the project folder holds as a regular file. Entry names are
 * built only from fixed names and validated SHA-256 strings.
 */
final class ArchiveWriter
{
    /** The copy, beside the zip in the job folder; removed once the zip is written. */
    public const COPY_FILE = 'project.tiffin';

    private const SHA256 = '/^[0-9a-f]{64}\z/';

    /**
     * Writes the archive to $target (a new file, normally
     * `<job>/archive.zip`) from the open project $db whose folder is
     * $projectFolder. $facts gives the creation time, version, and project
     * name; the returned manifest adds the copy's format version, size,
     * hash, and attachment count. Must not run inside a transaction. On
     * failure the copy and the partial zip are removed.
     */
    public function write(Database $db, string $projectFolder, string $target, ArchiveManifest $facts): ArchiveManifest
    {
        if ($db->inTransaction()) {
            throw new \LogicException('VACUUM INTO cannot run inside a transaction.');
        }
        if (Fs::exists($target)) {
            throw new \RuntimeException('Cannot write the archive: the path already exists.');
        }
        $copy = dirname($target) . '/' . self::COPY_FILE;
        if (Fs::exists($copy)) {
            throw new \RuntimeException('Cannot copy the project: the path already exists.');
        }
        try {
            $db->run('VACUUM INTO ?', [$copy]);
            $changed = ErrorTrap::run(static fn (): bool => chmod($copy, Fs::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
            [$version, $hashes] = self::readCopy($copy);
            $files = self::attachmentFiles($projectFolder, $hashes);
            $size = Fs::size($copy);
            $sha256 = ErrorTrap::run(static fn (): string|false => hash_file('sha256', $copy), 'Cannot read the copy.');
            if (!is_string($sha256)) {
                throw new \RuntimeException('Cannot read the copy.');
            }
            $manifest = $facts->withProject($version, $size, $sha256, count($files));
            self::zip($target, $manifest, $copy, $files);
            $changed = ErrorTrap::run(static fn (): bool => chmod($target, Fs::FILE_MODE), 'Cannot set the file mode.');
            if (!$changed) {
                throw new \RuntimeException('Cannot set the file mode.');
            }
        } catch (\Throwable $error) {
            Fs::remove($target);
            Fs::remove($copy);

            throw $error;
        }
        Fs::remove($copy);

        return $manifest;
    }

    /**
     * The copy's format version and its distinct, valid attachment hashes.
     *
     * @return array{0: int, 1: list<string>}
     */
    private static function readCopy(string $copy): array
    {
        $db = Sqlite::openUntrusted($copy);
        try {
            $version = $db->value('PRAGMA user_version');
            $hashes = [];
            foreach ($db->all('SELECT DISTINCT "sha256" FROM "_tiffin_attachments" ORDER BY "sha256"') as $row) {
                if (is_string($row['sha256']) && preg_match(self::SHA256, $row['sha256']) === 1) {
                    $hashes[] = $row['sha256'];
                }
            }
        } finally {
            $db->close();
        }
        if (!is_int($version) || $version < 1) {
            throw new \RuntimeException('The copy has no format version.');
        }

        return [$version, $hashes];
    }

    /**
     * The attachment files that exist as regular files below real folders
     * (no symbolic link is followed), by entry name.
     *
     * @param list<string> $hashes
     * @return array<string, string> entry name => path
     */
    private static function attachmentFiles(string $projectFolder, array $hashes): array
    {
        $files = [];
        $root = $projectFolder . '/attachments';
        if (!Fs::isDir($root)) {
            return [];
        }
        foreach ($hashes as $sha256) {
            $folder = $root . '/' . substr($sha256, 0, 2);
            $path = $folder . '/' . $sha256;
            if (Fs::isDir($folder) && Fs::isFile($path)) {
                $files['attachments/' . substr($sha256, 0, 2) . '/' . $sha256] = $path;
            }
        }

        return $files;
    }

    /** @param array<string, string> $files entry name => path */
    private static function zip(string $target, ArchiveManifest $manifest, string $copy, array $files): void
    {
        $zip = new \ZipArchive();
        $opened = ErrorTrap::run(static fn (): bool|int => $zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL), 'Cannot create the archive.');
        if ($opened !== true) {
            throw new \RuntimeException('Cannot create the archive.');
        }
        $ok = $zip->addFromString(ZipCheck::MANIFEST, $manifest->toJson())
            && $zip->setCompressionName(ZipCheck::MANIFEST, \ZipArchive::CM_DEFLATE)
            && $zip->addFile($copy, ZipCheck::PROJECT)
            && $zip->setCompressionName(ZipCheck::PROJECT, \ZipArchive::CM_DEFLATE);
        foreach ($files as $name => $path) {
            $ok = $ok && $zip->addFile($path, $name) && $zip->setCompressionName($name, \ZipArchive::CM_STORE);
        }
        if (!$ok) {
            $zip->unchangeAll();
            $zip->close();

            throw new \RuntimeException('Cannot add to the archive.');
        }
        $closed = ErrorTrap::run(static fn (): bool => $zip->close(), 'Cannot write the archive.');
        if (!$closed) {
            throw new \RuntimeException('Cannot write the archive.');
        }
    }
}
