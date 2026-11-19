<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use InvalidArgumentException;
use TiffinDB\Core\App\TableSummary;

/**
 * The data of the `sidebar` partial (M2 spec part 2 section 3.2;
 * `docs/design/m2/list-and-search.md` section 6), from the project's key
 * and name and the `tables` and `trashCount` of a record listing or search
 * result. It takes strings rather than the project record because the web
 * layer may not name Core\System classes.
 *
 * M3 (spec part 2 section 4): with $canImportExport (the core's `io.use`
 * answer for the person, P132), `data` holds the paths of the Data group's
 * "Import a file" and "Export" links; otherwise it is null and the group
 * is not drawn. Neither link is ever current: those pages use breadcrumbs.
 */
final class Sidebar
{
    private const KEY = '/^[a-z2-7]{12}\z/';

    private function __construct()
    {
    }

    /**
     * @param list<TableSummary> $tables in table order
     * @return array{projectName: string, switchPath: string, tables: list<array{name: string, count: string, path: string, current: bool}>, schemaPath: string, trashCount: string, data: array{importPath: string, exportPath: string}|null}
     */
    public static function build(
        string $projectKey,
        string $projectName,
        array $tables,
        int $trashCount,
        ?string $currentTableKey,
        bool $canImportExport = false,
    ): array {
        if (preg_match(self::KEY, $projectKey) !== 1) {
            throw new InvalidArgumentException('Invalid project key.');
        }
        $items = [];
        foreach ($tables as $summary) {
            if (!$summary instanceof TableSummary) {
                throw new InvalidArgumentException('The sidebar lists TableSummary values.');
            }
            $key = $summary->table->key;
            if (preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException('Invalid table key.');
            }
            $items[] = [
                'name' => $summary->table->name,
                'count' => number_format($summary->recordCount),
                'path' => "/projects/{$projectKey}/tables/{$key}",
                'current' => $key === $currentTableKey,
            ];
        }

        return [
            'projectName' => $projectName,
            'switchPath' => '/projects',
            'tables' => $items,
            'schemaPath' => "/projects/{$projectKey}/tables",
            'trashCount' => number_format(max(0, $trashCount)),
            'data' => $canImportExport
                ? ['importPath' => "/projects/{$projectKey}/import", 'exportPath' => "/projects/{$projectKey}/export"]
                : null,
        ];
    }
}
