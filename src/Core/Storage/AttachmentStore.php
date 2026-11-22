<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * The stored files of one project folder, at `attachments/<first 2
 * hex>/<64 hex>` (format section 8, ADR 0018 decision 4, M4 spec section
 * 3.2). Files only: no SQL, and no decision about whether a file is still
 * used (that is a database question, answered by Core\Query\Attachments
 * inside a write). Every path is built by folderPath() or filePath() from a
 * hash checked against HASH first; nothing is built from user text.
 *
 * place() and delete() take the project's Database and require its write
 * transaction to be open, so SQLite's write lock orders every placement
 * against every deletion in the same project (section 3.4). The folders
 * and the files are never followed through a symbolic link.
 */
final class AttachmentStore
{
    public const DIR = 'attachments';
    /** A stored file's name: a SHA-256 in lower-case hex. */
    public const HASH = '/^[0-9a-f]{64}\z/';
    /** A first-level folder's name: two lower-case hex characters. */
    private const PREFIX = '/^[0-9a-f]{2}\z/';

    private readonly string $folder;
    private readonly string $root;

    /** $projectFolder must be an existing directory, not a symbolic link. */
    public function __construct(string $projectFolder)
    {
        $trimmed = rtrim($projectFolder, '/');
        if ($trimmed === '' || str_contains($trimmed, "\0") || !Fs::isDir($trimmed)) {
            throw new \InvalidArgumentException('The project folder must be an existing directory.');
        }
        $this->folder = $trimmed;
        $this->root = $trimmed . '/' . self::DIR;
    }

    /**
     * Hashes and checks a staged upload: its size (Fs::size), its SHA-256
     * (Fs::sha256, streamed), and MediaType::ofFile. \RuntimeException when
     * the path is a symbolic link or anything but a regular file, or when
     * the size changed while it was hashed.
     */
    public function inspect(string $stagedPath): StoredUpload
    {
        $size = Fs::size($stagedPath);
        $sha256 = Fs::sha256($stagedPath);
        $mediaType = MediaType::ofFile($stagedPath);
        if (Fs::size($stagedPath) !== $size) {
            throw new \RuntimeException('The staged file changed while it was checked.');
        }

        return new StoredUpload($stagedPath, $sha256, $size, $mediaType);
    }

    /**
     * Puts the staged file at `attachments/<first 2>/<64>`. Must be called
     * inside this project's Database::write() (\LogicException otherwise).
     * First the staged file is checked again, whoever built $upload: it
     * must still be a regular file (not a link) whose size, SHA-256
     * (hashed again, Fs::sha256), and MediaType::ofFile equal the upload's;
     * else \RuntimeException, nothing moved (a swap after inspect is caught).
     * Nothing at the target: makes `attachments/` and the `<2>` folder
     * (0750) when absent, then Fs::moveIn (a rename on the same file
     * system; mode 0640), and returns true. A regular file at the target
     * of the upload's size whose own SHA-256 equals the hash: nothing is
     * moved, the staged file is left for the caller to remove, and false is
     * returned. A regular file of that size whose content does not match
     * its name (damaged or planted; it is no row's content) is removed and
     * the staged file moved in its place (true). Anything else at the
     * target (a link, a folder, a different size), or a folder on the way
     * that is not a real directory: \RuntimeException, nothing moved.
     *
     * When the move succeeds and the caller's transaction then rolls back,
     * the file stays as an orphan that the sweep removes (ADR 0018 decision
     * 6): a row never names a file that is not there.
     */
    public function place(Database $inWrite, StoredUpload $upload): bool
    {
        $this->requireWrite($inWrite);
        $folder = $this->folderPath($upload->sha256);
        $target = $this->filePath($upload->sha256);
        if (!Fs::isFile($upload->path) || Fs::size($upload->path) !== $upload->size
            || !hash_equals($upload->sha256, Fs::sha256($upload->path))
            || MediaType::ofFile($upload->path) !== $upload->mediaType) {
            throw new \RuntimeException('The staged file is not the inspected upload.');
        }
        foreach ([$this->root, $folder] as $directory) {
            if (!Fs::exists($directory)) {
                Fs::makeDir($directory);
            }
            if (!Fs::isDir($directory)) {
                throw new \RuntimeException('The attachments folder is not a directory.');
            }
        }
        if (Fs::exists($target)) {
            if (!Fs::isFile($target) || Fs::size($target) !== $upload->size) {
                throw new \RuntimeException('Something else is stored under the hash.');
            }
            if (hash_equals($upload->sha256, Fs::sha256($target))) {
                return false;
            }
            Fs::remove($target);
        }
        Fs::moveIn($upload->path, $target);

        return true;
    }

    /** The stored file's absolute path when it is a regular file (not a link) inside the folders; else null. */
    public function path(string $sha256): ?string
    {
        $folder = $this->folderPath($sha256);
        $target = $this->filePath($sha256);
        if (!Fs::isDir($this->root) || !Fs::isDir($folder) || !Fs::isFile($target)) {
            return null;
        }

        return $target;
    }

    /**
     * Deletes the stored file when present (Fs::remove); inside this
     * project's write, like place(). Nothing there is not an error;
     * anything but a regular file there (a link, a folder), or a folder on
     * the way that is not a real directory, is \RuntimeException with
     * nothing removed: it is not TiffinDB's.
     */
    public function delete(Database $inWrite, string $sha256): void
    {
        $this->requireWrite($inWrite);
        $folder = $this->folderPath($sha256);
        $target = $this->filePath($sha256);
        foreach ([$this->root, $folder] as $directory) {
            if (!Fs::exists($directory)) {
                return;
            }
            if (!Fs::isDir($directory)) {
                throw new \RuntimeException('The attachments folder is not a directory.');
            }
        }
        if (!Fs::exists($target)) {
            return;
        }
        if (!Fs::isFile($target)) {
            throw new \RuntimeException('What is stored under the hash is not a regular file.');
        }
        Fs::remove($target);
    }

    /**
     * Every stored hash, sorted: the entries of `attachments/<2 hex>/` that
     * are regular files named with 64 lower-case hex characters starting
     * with the folder's two. Other entries (at either level) are skipped,
     * never deleted (they are not TiffinDB's), and reported with one
     * error_log line per call. An absent `attachments/` gives [].
     *
     * @return list<string>
     */
    public function storedHashes(): array
    {
        if (!Fs::exists($this->root)) {
            return [];
        }
        $hashes = [];
        $skipped = 0;
        try {
            if (!Fs::isDir($this->root)) {
                throw new \RuntimeException('The attachments folder is not a directory.');
            }
            foreach (Fs::entries($this->root) as $prefix) {
                if (preg_match(self::PREFIX, $prefix) !== 1 || !Fs::isDir($this->prefixPath($prefix))) {
                    $skipped++;

                    continue;
                }
                foreach (Fs::entries($this->prefixPath($prefix)) as $name) {
                    if (preg_match(self::HASH, $name) === 1 && str_starts_with($name, $prefix) && Fs::isFile($this->filePath($name))) {
                        $hashes[] = $name;
                    } else {
                        $skipped++;
                    }
                }
            }
        } catch (\RuntimeException $error) {
            error_log('TiffinDB: the attachments folder could not be listed (' . $error::class . ').');

            throw $error;
        }
        if ($skipped > 0) {
            error_log('TiffinDB: the attachments folder holds entries that are not stored files; skipped=' . (string) $skipped);
        }
        sort($hashes, SORT_STRING);

        return $hashes;
    }

    /** The `<2>` folder of a hash; \InvalidArgumentException unless the hash is valid. */
    private function folderPath(string $sha256): string
    {
        self::requireHash($sha256);

        return $this->prefixPath(substr($sha256, 0, 2));
    }

    /** The stored file of a hash; \InvalidArgumentException unless the hash is valid. */
    private function filePath(string $sha256): string
    {
        self::requireHash($sha256);

        return $this->prefixPath(substr($sha256, 0, 2)) . '/' . $sha256;
    }

    /** A first-level folder; \InvalidArgumentException unless the name is two lower-case hex characters. */
    private function prefixPath(string $prefix): string
    {
        if (preg_match(self::PREFIX, $prefix) !== 1) {
            throw new \InvalidArgumentException('A folder name must be two lower-case hex characters.');
        }

        return $this->root . '/' . $prefix;
    }

    private static function requireHash(string $sha256): void
    {
        if (preg_match(self::HASH, $sha256) !== 1) {
            throw new \InvalidArgumentException('A hash must be 64 lower-case hex characters.');
        }
    }

    /** \LogicException unless $db is this project's database with its write transaction open. */
    private function requireWrite(Database $db): void
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('The attachment store changes files only inside the project\'s write.');
        }
        $databaseFile = realpath($db->path());
        $projectFolder = realpath($this->folder);
        if ($databaseFile === false || $projectFolder === false || dirname($databaseFile) !== $projectFolder) {
            throw new \LogicException('The database is not this project\'s.');
        }
    }
}
