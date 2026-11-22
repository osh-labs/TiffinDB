<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Json;

use Closure;
use TiffinDB\Core\Io\Contract\ExportAttachment;
use TiffinDB\Core\Io\Contract\ExportCollection;
use TiffinDB\Core\Io\Contract\ExportProject;
use TiffinDB\Core\Io\Contract\ExportRecord;
use TiffinDB\Core\Io\Contract\ExportRelation;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportTable;

/** An ExportSource made of given values; records come from a generator per table, so nothing is held in memory. */
final class FakeExportSource implements ExportSource
{
    /**
     * @param list<ExportTable> $tables
     * @param Closure(string): iterable<ExportRecord> $records
     * @param list<ExportRelation> $relations
     * @param list<ExportAttachment> $attachments
     * @param list<ExportCollection> $collections
     */
    public function __construct(
        private readonly array $tables,
        private readonly Closure $records,
        private readonly array $relations = [],
        private readonly array $attachments = [],
        private readonly array $collections = [],
    ) {
    }

    public function project(): ExportProject
    {
        return new ExportProject('q7mzk2wbx4ae', 'Example Project', '2026-11-12T03:15:00Z', '2026-11-19T09:58:12Z', 3, '2026-11-19T10:15:00Z', '0.3.0-dev');
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function records(string $tableKey): iterable
    {
        return ($this->records)($tableKey);
    }

    public function relations(): array
    {
        return $this->relations;
    }

    public function attachments(): iterable
    {
        return $this->attachments;
    }

    public function collections(): array
    {
        return $this->collections;
    }
}
