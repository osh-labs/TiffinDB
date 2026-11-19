<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Io\IoLimits;

/**
 * The entry rules of a project archive (M3 spec section 11.3 step 1,
 * `docs/formats/tiffin.md` section 13.1), checked from the central
 * directory of an open ZipArchive before any byte is extracted. Entry
 * names are only ever compared with the allowlist below; none is used as
 * a path.
 *
 * Every entry must be `tiffindb-archive.json`, `project.tiffin`, or
 * `attachments/<2 hex>/<64 hex>` whose folder equals the first two
 * characters (folder entries `attachments/` and `attachments/<2 hex>/`
 * are accepted and ignored); names are compared as raw bytes. An entry
 * whose external attributes give it a Unix file type other than a
 * regular file (or a directory, for a folder entry), for example a
 * symbolic link, is refused like a foreign name. No duplicate names, no
 * encryption, stored or deflated only. Caps: the entry count, the
 * declared size of the project file, the manifest, and all entries
 * together (also against the free disk space less the reserve), and a
 * compression ratio for entries over 1 MiB (a decompression bomb is
 * refused before it is expanded; the bytes are counted again while each
 * entry is streamed).
 */
final class ZipCheck
{
    public const MANIFEST = 'tiffindb-archive.json';
    public const PROJECT = 'project.tiffin';
    /** A file entry, with the attachment folder equal to the first two characters of the hash. */
    public const FILE_NAME = '#^(?:tiffindb-archive\.json|project\.tiffin|attachments/([0-9a-f]{2})/\1[0-9a-f]{62})\z#';
    public const FOLDER_NAME = '#^attachments/(?:[0-9a-f]{2}/)?\z#';
    /** Entries above this many bytes must not expand more than MAX_RATIO times. */
    public const RATIO_FLOOR = 1048576;
    public const MAX_RATIO = 1000;

    private const UNIX_TYPE_MASK = 0170000;
    private const UNIX_FILE = 0100000;
    private const UNIX_DIR = 0040000;
    private const DOS_DIRECTORY = 0x10;

    /**
     * @param int $maxEntries IoLimits::ARCHIVE_MAX_ENTRIES unless a test lowers it
     * @param int $maxProjectBytes IoLimits::ARCHIVE_MAX_PROJECT_BYTES unless a test lowers it
     * @param int $maxTotalBytes IoLimits::ARCHIVE_MAX_TOTAL_BYTES unless a test lowers it
     */
    public function __construct(
        private readonly int $maxEntries = IoLimits::ARCHIVE_MAX_ENTRIES,
        private readonly int $maxProjectBytes = IoLimits::ARCHIVE_MAX_PROJECT_BYTES,
        private readonly int $maxTotalBytes = IoLimits::ARCHIVE_MAX_TOTAL_BYTES,
    ) {
    }

    /**
     * Checks every entry. Returns the index and declared size of each file
     * entry by name (folder entries left out); ArchiveRefused with the
     * first failing rule otherwise. $freeBytes is the free space where the
     * entries will be written, or null when it is unknown.
     *
     * @return array<string, array{index: int, size: int}>
     */
    public function check(\ZipArchive $zip, ?int $freeBytes): array
    {
        $count = $zip->count();
        if ($count > $this->maxEntries) {
            throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'entry count');
        }
        $limit = $this->maxTotalBytes;
        if ($freeBytes !== null) {
            $limit = min($limit, $freeBytes - IoLimits::DISK_RESERVE_BYTES);
        }
        $files = [];
        $seen = [];
        $total = 0;
        for ($index = 0; $index < $count; $index++) {
            $name = $zip->getNameIndex($index, \ZipArchive::FL_ENC_RAW);
            $stat = $zip->statIndex($index, \ZipArchive::FL_ENC_RAW);
            if (!is_string($name) || !is_array($stat)) {
                throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'unreadable entry');
            }
            $isFolder = preg_match(self::FOLDER_NAME, $name) === 1;
            if (!$isFolder && preg_match(self::FILE_NAME, $name) !== 1) {
                throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'entry name');
            }
            if (isset($seen[$name])) {
                throw new ArchiveRefused(ArchiveRefused::DUPLICATE, 'entry name twice');
            }
            $seen[$name] = true;
            self::checkType($zip, $index, $isFolder);
            if ((int) ($stat['encryption_method'] ?? -1) !== 0) {
                throw new ArchiveRefused(ArchiveRefused::ENCRYPTED, 'encrypted entry');
            }
            $method = (int) ($stat['comp_method'] ?? -1);
            if ($method !== \ZipArchive::CM_STORE && $method !== \ZipArchive::CM_DEFLATE) {
                throw new ArchiveRefused(ArchiveRefused::COMPRESSION, 'compression method');
            }
            $size = $stat['size'] ?? null;
            $compressed = $stat['comp_size'] ?? null;
            if (!is_int($size) || !is_int($compressed) || $size < 0 || $compressed < 0) {
                throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'entry size');
            }
            if ($isFolder) {
                if ($size !== 0) {
                    throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'folder with content');
                }
                continue;
            }
            if ($name === self::PROJECT && $size > $this->maxProjectBytes) {
                throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'project size');
            }
            if ($name === self::MANIFEST && $size > ArchiveManifest::MAX_BYTES) {
                throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'manifest size');
            }
            if ($size > self::RATIO_FLOOR && ($compressed === 0 || intdiv($size, $compressed) >= self::MAX_RATIO)) {
                throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'compression ratio');
            }
            $total += $size;
            if ($total > $limit) {
                throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'total size');
            }
            $files[$name] = ['index' => $index, 'size' => $size];
        }
        if (!isset($files[self::MANIFEST], $files[self::PROJECT])) {
            throw new ArchiveRefused(ArchiveRefused::MISSING_ENTRY, 'manifest or project');
        }

        return $files;
    }

    /** Refuses an entry that the external attributes mark as a link, device, or other non-file type. */
    private static function checkType(\ZipArchive $zip, int $index, bool $isFolder): void
    {
        $system = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $system, $attributes, \ZipArchive::FL_UNCHANGED)) {
            throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'unreadable attributes');
        }
        if ($system === \ZipArchive::OPSYS_UNIX) {
            $type = ($attributes >> 16) & self::UNIX_TYPE_MASK;
            if ($type !== 0 && $type !== ($isFolder ? self::UNIX_DIR : self::UNIX_FILE)) {
                throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'entry type');
            }

            return;
        }
        if (!$isFolder && ($attributes & self::DOS_DIRECTORY) !== 0) {
            throw new ArchiveRefused(ArchiveRefused::ENTRY_NAME, 'entry type');
        }
    }
}
