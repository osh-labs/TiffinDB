<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** The read-only view of a project an exporter receives (M3 spec section 3.3); built by the core. */
interface ExportSource
{
    public function project(): ExportProject;

    /** @return list<ExportTable> the tables in scope, in table order */
    public function tables(): array;

    /** @return iterable<ExportRecord> live records of the table by id, streamed in pages of 500 */
    public function records(string $tableKey): iterable;

    /** @return list<ExportRelation> relations whose both tables are in scope */
    public function relations(): array;

    /** @return iterable<ExportAttachment> attachments linked from live records in scope */
    public function attachments(): iterable;
}
