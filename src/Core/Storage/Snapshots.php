<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * Project snapshots (ADR 0012 decision 3, M1 spec section 4.4): consistent
 * copies made with `VACUUM INTO`, named
 * `<YYYYMMDDTHHMMSSZ>-<reason>-<4 key characters>.tiffin` in the project's
 * `snapshots/` folder. The 20 newest automatic snapshots are kept; manual
 * ones are never pruned.
 */
final class Snapshots
{
    public const KEEP_AUTOMATIC = 20;
    public const NAME = '/^(\d{8}T\d{6}Z)-([a-z-]+)-([a-z2-7]{4})\.tiffin\z/';
    public const FOLDER = 'snapshots';
    private const TIME_FORMAT = 'Ymd\THis\Z';
    private const SUFFIX_LENGTH = 4;
    private const MAX_ATTEMPTS = 10;

    public function __construct(private readonly Clock $clock, private readonly Keys $keys)
    {
    }

    /**
     * VACUUM INTO <folder>/snapshots/<name>; creates snapshots/ if missing;
     * prunes afterwards. Refused (\LogicException) inside a transaction.
     */
    public function take(Database $db, string $projectFolder, SnapshotReason $reason): SnapshotInfo
    {
        if ($db->inTransaction()) {
            throw new \LogicException('A snapshot must not be taken inside a transaction.');
        }
        $folder = self::folder($projectFolder);
        Fs::makeDir($folder);

        $takenAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $stamp = $takenAt->format(self::TIME_FORMAT);
        $file = null;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $name = $stamp . '-' . $reason->value . '-' . $this->keys->short(self::SUFFIX_LENGTH) . '.tiffin';
            if (!file_exists($folder . '/' . $name)) {
                $file = $name;

                break;
            }
        }
        if ($file === null) {
            throw new \RuntimeException('No free snapshot name after ' . self::MAX_ATTEMPTS . ' attempts.');
        }
        $path = $folder . '/' . $file;

        $db->run('VACUUM INTO ?', [$path]);
        ErrorTrap::run(static fn (): bool => chmod($path, Fs::FILE_MODE), 'Cannot set the snapshot file mode.');
        clearstatcache(true, $path);
        $size = ErrorTrap::run(static fn (): int|false => filesize($path), 'Cannot read the snapshot size.');

        $info = new SnapshotInfo($file, self::parseTime($stamp) ?? $takenAt, $reason, $size === false ? 0 : $size);
        $this->prune($folder, $file, $reason);

        return $info;
    }

    /**
     * Snapshots, newest first (ties by file name, descending). Files whose
     * names do not match NAME, or name an unknown reason, are ignored.
     *
     * @return list<SnapshotInfo>
     */
    public function list(string $projectFolder): array
    {
        $folder = self::folder($projectFolder);
        if (!is_dir($folder)) {
            return [];
        }
        $names = ErrorTrap::run(static fn (): array|false => scandir($folder), 'Cannot list the snapshots.');
        if ($names === false) {
            throw new \RuntimeException('Cannot list the snapshots.');
        }
        $found = [];
        foreach ($names as $name) {
            if (preg_match(self::NAME, $name, $match) !== 1) {
                continue;
            }
            $reason = SnapshotReason::tryFrom($match[2]);
            $takenAt = self::parseTime($match[1]);
            $path = $folder . '/' . $name;
            if ($reason === null || $takenAt === null || !is_file($path) || is_link($path)) {
                continue;
            }
            $size = filesize($path);
            $found[] = new SnapshotInfo($name, $takenAt, $reason, $size === false ? 0 : $size);
        }
        usort($found, static fn (SnapshotInfo $a, SnapshotInfo $b): int => [$b->takenAt, $b->file] <=> [$a->takenAt, $a->file]);

        return $found;
    }

    private static function folder(string $projectFolder): string
    {
        return rtrim($projectFolder, '/') . '/' . self::FOLDER;
    }

    private static function parseTime(string $stamp): ?\DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!' . self::TIME_FORMAT, $stamp, new \DateTimeZone('UTC'));
        if ($time === false || $time->format(self::TIME_FORMAT) !== $stamp) {
            return null;
        }

        return $time;
    }

    /** Deletes the oldest automatic snapshots beyond KEEP_AUTOMATIC; the one just taken always stays. */
    private function prune(string $folder, string $justTaken, SnapshotReason $reason): void
    {
        $kept = $reason->isAutomatic() ? 1 : 0;
        foreach ($this->list(dirname($folder)) as $snapshot) {
            if (!$snapshot->reason->isAutomatic() || $snapshot->file === $justTaken) {
                continue;
            }
            if ($kept < self::KEEP_AUTOMATIC) {
                $kept++;

                continue;
            }
            Fs::remove($folder . '/' . $snapshot->file);
        }
    }
}
