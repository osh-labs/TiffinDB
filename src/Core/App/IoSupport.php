<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\IoLimits;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * Helpers shared by the import, export, and archive operations: upload
 * checks, the names the application gives files, the owner file of export
 * and archive jobs, and the job lock of job folders that have no job file.
 *
 * @internal Used by the Core\App operation classes only.
 */
final class IoSupport
{
    /** The owner file of export and archive jobs (M3 spec sections 5.1, 11.6). */
    public const OWNER_FILE = 'owner.json';
    public const MAX_CLIENT_NAME = 255;
    public const CLIENT_NAME_FALLBACK = 'file';
    public const MAX_FILE_BASE = 60;
    private const MAX_OWNER_BYTES = 4096;

    private function __construct()
    {
    }

    /**
     * The uploaded file's own name as text for people (section 13.1):
     * trimmed, control characters removed, cut to 255 characters, `file`
     * when nothing is left. It is never used as a path.
     */
    public static function clientName(string $name): string
    {
        if (!Text::isUtf8($name)) {
            return self::CLIENT_NAME_FALLBACK;
        }
        $name = (string) preg_replace('/(?![\x{200C}\x{200D}])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $name);
        $name = Text::trim($name);
        if (Text::length($name) > self::MAX_CLIENT_NAME) {
            $name = Text::trim((string) preg_replace('/^(.{' . self::MAX_CLIENT_NAME . '}).*\z/us', '$1', $name));
        }

        return $name === '' ? self::CLIENT_NAME_FALLBACK : $name;
    }

    /** The lower-case extension of a client name, '' when it has none that a manifest could list. */
    public static function extension(string $clientName): string
    {
        if (preg_match('/\.([A-Za-z0-9]{1,10})\z/', $clientName, $match) !== 1) {
            return '';
        }

        return strtolower($match[1]);
    }

    /**
     * A download name base (M3 spec section 8.2): letters, digits, space,
     * `-` and `_` kept, spaces to `-`, ASCII lower case, cut to 60
     * characters; $fallback when nothing is left.
     */
    public static function fileBase(string $name, string $fallback): string
    {
        $base = Text::isUtf8($name) ? (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $name) : '';
        $base = strtolower(str_replace(' ', '-', $base));
        $base = (string) preg_replace('/^(.{0,' . self::MAX_FILE_BASE . '}).*\z/us', '$1', $base);

        return $base === '' ? $fallback : $base;
    }

    /**
     * Checks an upload on disk before it is moved into a job folder and
     * returns its size: it must be a regular file (`problem.upload.missing`),
     * at most MAX_UPLOAD_BYTES (`problem.upload.too_large`), and fit in the
     * free space of the data directory less the reserve (`problem.upload.no_space`).
     */
    public static function checkUpload(Context $c, string $path): int
    {
        if ($path === '' || str_contains($path, "\0") || !Fs::isFile($path)) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.missing')]);
        }
        $size = Fs::size($path);
        if ($size > IoLimits::MAX_UPLOAD_BYTES) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.too_large', ['max' => IoLimits::MAX_UPLOAD_BYTES])]);
        }
        $free = self::freeSpace($c->data->path());
        if ($free !== null && $size > $free - IoLimits::DISK_RESERVE_BYTES) {
            throw new ValidationFailed([new Problem('file', 'problem.upload.no_space')]);
        }

        return $size;
    }

    /** SHA-256 of a file in a job folder. */
    public static function sha256(string $path): string
    {
        $hash = ErrorTrap::run(static fn (): string|false => hash_file('sha256', $path), 'Cannot read the upload.');
        if (!is_string($hash)) {
            throw new \RuntimeException('Cannot read the upload.');
        }

        return $hash;
    }

    /**
     * Writes the owner file of a new export or archive job folder.
     *
     * @param array<string, int|string> $owner
     */
    public static function writeOwner(string $folder, array $owner): void
    {
        Fs::writeNew($folder . '/' . self::OWNER_FILE, json_encode($owner, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The owner file of a job folder, or null when it is missing, not a
     * regular file, too large, or not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function readOwner(string $folder): ?array
    {
        $path = $folder . '/' . self::OWNER_FILE;
        if (!Fs::isFile($path) || Fs::size($path) > self::MAX_OWNER_BYTES) {
            return null;
        }
        $text = ErrorTrap::run(static fn (): string|false => file_get_contents($path), 'Cannot read the job owner.');
        if (!is_string($text)) {
            return null;
        }
        try {
            $owner = json_decode($text, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($owner) && !array_is_list($owner) ? $owner : null;
    }

    /** Whether the owner file names exactly this person (and, when given, this project). */
    public static function ownedBy(string $folder, int $userId, ?string $projectKey): bool
    {
        $owner = self::readOwner($folder);
        if ($owner === null || ($owner['user'] ?? null) !== $userId) {
            return false;
        }

        return $projectKey === null || ($owner['project'] ?? null) === $projectKey;
    }

    /**
     * Takes the job lock of a job folder (exclusive, non-blocking flock on
     * `job.lock`, as ImportJob::lock); Busy when another request holds it.
     *
     * @return resource
     */
    public static function lock(string $folder)
    {
        $path = $folder . '/' . StagingArea::LOCK;
        if (!Fs::isFile($path)) {
            throw new \RuntimeException('The job has no lock file.');
        }
        $handle = ErrorTrap::run(static fn (): mixed => fopen($path, 'r'), 'Cannot open the job lock.');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cannot open the job lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new Busy('Another request is working on this job.');
        }

        return $handle;
    }

    /** @param resource|null $handle */
    public static function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Runs plugin code: a PluginFailure passes through, anything else is
     * logged with the plugin's ref and becomes PluginFailure(REASON_FAILED)
     * (M3 spec section 3.2). Details go to the server log only.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function plugin(PluginInfo $plugin, \Closure $work): mixed
    {
        try {
            return $work();
        } catch (PluginFailure $failure) {
            error_log('TiffinDB: plugin ' . $plugin->ref() . ' failed (' . $failure->reason . ').');

            throw $failure;
        } catch (\Throwable $error) {
            error_log('TiffinDB: plugin ' . $plugin->ref() . ' failed (' . $error::class . ').');

            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The plugin failed.', $error);
        }
    }

    /** Removes a job folder; a failure is logged, never thrown (the sweep removes it later). */
    public static function removeJob(Context $c, string $kind, string $key): void
    {
        try {
            $c->staging()->remove($kind, $key);
        } catch (\Throwable $error) {
            error_log('TiffinDB: could not remove a staging job (' . $error::class . ').');
        }
    }

    private static function freeSpace(string $path): ?int
    {
        try {
            $free = ErrorTrap::run(static fn (): float|false => disk_free_space($path), 'Cannot read the free space.');
        } catch (\RuntimeException) {
            return null;
        }

        return is_float($free) ? (int) min($free, (float) PHP_INT_MAX) : null;
    }
}
