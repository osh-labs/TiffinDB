<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * Opens an uploaded project archive (M3 spec section 11.3) inside its
 * `archives` job folder: checks the entries (ZipCheck), extracts the
 * manifest to `manifest.json` and the database to `source.tiffin`, reads
 * the manifest, and compares the database with it. Attachment entries
 * are streamed later, one by one, by copyAttachment().
 *
 * Security-critical. The archive is opened read-only with consistency
 * checks, never extracted with extractTo, and never trusted for a path:
 * every file written gets a name the application chose, is created
 * exclusively, and is filled by counting bytes, so a header that lies
 * about a size cannot make a copy larger than the declared size (or the
 * cap). Any failure is ArchiveRefused; the caller removes the job folder.
 */
final class ArchiveReader
{
    public const MANIFEST_FILE = 'manifest.json';
    public const PROJECT_FILE = 'source.tiffin';

    private const CHUNK = 65536;

    /** @param array<string, array{index: int, size: int}> $files */
    private function __construct(
        private ?\ZipArchive $zip,
        private readonly array $files,
        public readonly ArchiveManifest $manifest,
        private readonly string $projectPath,
        public readonly int $uploadSize,
        public readonly string $uploadSha256,
    ) {
    }

    /**
     * Checks and opens $zipPath, writing the extracted files into
     * $jobFolder (which must exist and hold neither file yet). $freeBytes
     * is the free disk space there, or null to ask the file system.
     */
    public static function open(string $zipPath, string $jobFolder, ?int $freeBytes = null, ?ZipCheck $check = null): self
    {
        if (!Fs::isFile($zipPath) || !Fs::isDir($jobFolder)) {
            throw new ArchiveRefused(ArchiveRefused::MISSING_ENTRY, 'upload or job folder');
        }
        $uploadSize = Fs::size($zipPath);
        $uploadSha256 = ErrorTrap::run(static fn (): string|false => hash_file('sha256', $zipPath), 'Cannot read the upload.');
        if (!is_string($uploadSha256)) {
            throw new \RuntimeException('Cannot read the upload.');
        }
        $zip = new \ZipArchive();
        $opened = ErrorTrap::run(static fn (): bool|int => $zip->open($zipPath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS), 'Cannot open the archive.');
        if ($opened !== true) {
            // libzip reports a name that appears twice as ER_EXISTS when it reads the central directory.
            throw new ArchiveRefused($opened === \ZipArchive::ER_EXISTS ? ArchiveRefused::DUPLICATE : ArchiveRefused::MISSING_ENTRY, 'zip open');
        }
        try {
            $files = ($check ?? new ZipCheck())->check($zip, $freeBytes ?? self::freeSpace($jobFolder));
            $manifestPath = $jobFolder . '/' . self::MANIFEST_FILE;
            $projectPath = $jobFolder . '/' . self::PROJECT_FILE;
            $entry = $files[ZipCheck::MANIFEST];
            self::stream($zip, $entry['index'], $manifestPath, min($entry['size'], ArchiveManifest::MAX_BYTES), ArchiveRefused::MANIFEST);
            $json = ErrorTrap::run(static fn (): string|false => file_get_contents($manifestPath), 'Cannot read the manifest.');
            $manifest = ArchiveManifest::fromJson(is_string($json) ? $json : '');
            $entry = $files[ZipCheck::PROJECT];
            [$size, $sha256] = self::stream($zip, $entry['index'], $projectPath, $entry['size'], ArchiveRefused::DAMAGED);
            if ($size !== $manifest->projectSize || !hash_equals($manifest->projectSha256, $sha256)) {
                throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'project size or hash');
            }
        } catch (\Throwable $error) {
            $zip->close();

            throw $error;
        }

        return new self($zip, $files, $manifest, $projectPath, $uploadSize, $uploadSha256);
    }

    /** The extracted database (`source.tiffin`), equal in size and hash to the manifest's. */
    public function projectPath(): string
    {
        return $this->projectPath;
    }

    /**
     * The hashes of the attachment entries, sorted.
     *
     * @return list<string>
     */
    public function attachmentHashes(): array
    {
        $hashes = [];
        foreach (array_keys($this->files) as $name) {
            if (str_starts_with((string) $name, 'attachments/')) {
                $hashes[] = substr((string) $name, 15);
            }
        }
        sort($hashes, SORT_STRING);

        return $hashes;
    }

    /**
     * Streams the entry `attachments/<2>/<$sha256>` to $target (a new
     * file) and keeps it only when the archive holds the entry, its
     * declared size is $size, and the bytes read are exactly $size bytes
     * whose SHA-256 is $sha256. Returns whether the file was kept; a file
     * not kept is removed again. Folders above $target must exist.
     */
    public function copyAttachment(string $sha256, string $target, int $size): bool
    {
        if (preg_match('/^[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('An attachment is named by its SHA-256.');
        }
        $entry = $this->files['attachments/' . substr($sha256, 0, 2) . '/' . $sha256] ?? null;
        if ($entry === null || $entry['size'] !== $size || Fs::exists($target)) {
            return false;
        }
        try {
            [$count, $hash] = self::stream($this->zip(), $entry['index'], $target, $size, ArchiveRefused::DAMAGED);
        } catch (ArchiveRefused|\RuntimeException) {
            Fs::remove($target);

            return false;
        }
        if ($count !== $size || !hash_equals($sha256, $hash)) {
            Fs::remove($target);

            return false;
        }

        return true;
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    private function zip(): \ZipArchive
    {
        if ($this->zip === null) {
            throw new \LogicException('The archive is closed.');
        }

        return $this->zip;
    }

    /**
     * Copies one entry to a new file with mode 0640, counting bytes and
     * hashing them; stops with ArchiveRefused('too-large') as soon as more
     * than $limit bytes arrive, and refuses with $reason when the entry
     * cannot be read. Returns the byte count and SHA-256.
     *
     * @return array{0: int, 1: string}
     */
    private static function stream(\ZipArchive $zip, int $index, string $target, int $limit, string $reason): array
    {
        $in = ErrorTrap::run(static fn (): mixed => $zip->getStreamIndex($index, \ZipArchive::FL_UNCHANGED), 'Cannot read the entry.');
        if (!is_resource($in)) {
            throw new ArchiveRefused($reason, 'entry stream');
        }
        try {
            $out = ErrorTrap::run(static fn (): mixed => fopen($target, 'xb'), 'Cannot create the file.');
            if (!is_resource($out)) {
                throw new \RuntimeException('Cannot create the file.');
            }
            try {
                $changed = ErrorTrap::run(static fn (): bool => chmod($target, Fs::FILE_MODE), 'Cannot set the file mode.');
                if (!$changed) {
                    throw new \RuntimeException('Cannot set the file mode.');
                }
                $hash = hash_init('sha256');
                $count = 0;
                while (true) {
                    try {
                        $chunk = ErrorTrap::run(static fn (): string|false => fread($in, self::CHUNK), 'Cannot read the entry.');
                    } catch (\RuntimeException $error) {
                        throw new ArchiveRefused($reason, 'entry read', $error);
                    }
                    if ($chunk === false) {
                        throw new ArchiveRefused($reason, 'entry read');
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $count += strlen($chunk);
                    if ($count > $limit) {
                        throw new ArchiveRefused(ArchiveRefused::TOO_LARGE, 'entry larger than declared');
                    }
                    hash_update($hash, $chunk);
                    $written = ErrorTrap::run(static fn (): int|false => fwrite($out, $chunk), 'Cannot write the file.');
                    if ($written !== strlen($chunk)) {
                        throw new \RuntimeException('Cannot write the file.');
                    }
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }

        return [$count, hash_final($hash)];
    }

    /** Free bytes where the job folder is, or null when the file system does not say. */
    private static function freeSpace(string $folder): ?int
    {
        try {
            $free = ErrorTrap::run(static fn (): float|false => disk_free_space($folder), 'Cannot read the free space.');
        } catch (\RuntimeException) {
            return null;
        }

        return is_float($free) ? (int) min($free, (float) PHP_INT_MAX) : null;
    }
}
