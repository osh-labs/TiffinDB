<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** What a file holds (M3 spec section 3.2): at least one table, and a short description such as "CSV, commas, UTF-8". */
final class Inspection
{
    /** @param list<SourceTable> $tables */
    public function __construct(public readonly array $tables, public readonly string $readAs)
    {
        if (!array_is_list($tables) || $tables === []) {
            throw new \InvalidArgumentException('An inspection has at least one table.');
        }
        foreach ($tables as $table) {
            if (!$table instanceof SourceTable) {
                throw new \InvalidArgumentException('Tables are SourceTable values.');
            }
        }
        if (!Values::isText($readAs, 0, 200)) {
            throw new \InvalidArgumentException('The description is at most 200 characters.');
        }
    }
}
