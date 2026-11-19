<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

/**
 * What a rebuild copied, emptied, dropped, and replaced (M3 spec sections
 * 11.5 and 13.4): counts, field keys, and names from the rebuilt
 * project's own metadata, never a record value.
 */
final class RebuildReport
{
    /**
     * @param array<string, array{table: string, field: string, count: int}> $valuesEmptied by field key, in table and field order
     */
    public function __construct(
        public readonly string $importLogKey,
        public readonly int $tables,
        public readonly int $records,
        public readonly array $valuesEmptied,
        public readonly int $linksDropped,
        public readonly int $formsDropped,
        public readonly int $formsExtended,
        public readonly int $filesMissing,
        public readonly int $filesSkipped,
        public readonly int $timesReplaced,
        public readonly int $personKeysCleared,
        public readonly int $recordsSkipped,
        public readonly int $attachmentsDropped,
    ) {
    }

    /** Every value emptied, over all fields. */
    public function valuesEmptiedTotal(): int
    {
        return array_sum(array_map(static fn (array $entry): int => $entry['count'], $this->valuesEmptied));
    }

    /** Whether anything was emptied, dropped, missing, skipped, or replaced. */
    public function isClean(): bool
    {
        return $this->valuesEmptied === [] && $this->linksDropped === 0 && $this->formsDropped === 0 && $this->formsExtended === 0
            && $this->filesMissing === 0 && $this->filesSkipped === 0 && $this->timesReplaced === 0 && $this->personKeysCleared === 0
            && $this->recordsSkipped === 0 && $this->attachmentsDropped === 0;
    }

    /**
     * The `archive` member of the import log's report object
     * (`docs/formats/tiffin.md` section 4.7.1).
     *
     * @return array<string, int>
     */
    public function archiveMember(): array
    {
        return [
            'values_emptied' => $this->valuesEmptiedTotal(),
            'links_dropped' => $this->linksDropped,
            'forms_dropped' => $this->formsDropped,
            'forms_extended' => $this->formsExtended,
            'files_missing' => $this->filesMissing,
        ];
    }
}
