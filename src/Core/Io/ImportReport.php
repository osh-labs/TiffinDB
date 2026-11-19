<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/**
 * One row of `_tiffin_import_log` (`docs/formats/tiffin.md` section 4.7)
 * with its `report` object (section 4.7.1): counts and row numbers, never
 * cell values (M3 spec section 6.7).
 */
final class ImportReport
{
    public const SOURCE_FILE = 'file';
    public const SOURCE_ARCHIVE = 'archive';

    /** @param array<string, mixed> $report the report object; members omitted when empty */
    public function __construct(
        public readonly string $key,
        public readonly string $importedAt,
        public readonly ?string $importedBy,
        public readonly string $source,
        public readonly ?string $plugin,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly string $fileSha256,
        public readonly ?string $tableKey,
        public readonly bool $newTable,
        public readonly int $rowsRead,
        public readonly int $rowsImported,
        public readonly int $rowsSkipped,
        public readonly int $valuesEmptied,
        public readonly int $linksAdded,
        public readonly ?string $snapshot,
        public readonly array $report,
    ) {
        if ($source !== self::SOURCE_FILE && $source !== self::SOURCE_ARCHIVE) {
            throw new \InvalidArgumentException('The source is file or archive.');
        }
        if (preg_match('/^[0-9a-f]{64}\z/', $fileSha256) !== 1) {
            throw new \InvalidArgumentException('The file hash is a SHA-256 hex digest.');
        }
        foreach ([$fileSize, $rowsRead, $rowsImported, $rowsSkipped, $valuesEmptied, $linksAdded] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException('Counts are 0 or more.');
            }
        }
    }

    /** The report object's JSON text (`{}` when empty). */
    public function reportJson(): string
    {
        return json_encode((object) $this->report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
