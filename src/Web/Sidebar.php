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
 *
 * M4 (spec part 2 section 5): `collections` lists the project's
 * collections (plain data from the caller, so no core class is named),
 * each with its path and whether it is the one being viewed;
 * `newCollectionPath` is the editor for a new collection on the table
 * given, or null; `trashPath` is the trash screen (never current).
 */
final class Sidebar
{
    private const KEY = '/^[a-z2-7]{12}\z/';

    private function __construct()
    {
    }

    /**
     * @param list<TableSummary>                              $tables      in table order
     * @param list<array{key: string, name: string, count: int}> $collections in the order to show
     * @return array{projectName: string, switchPath: string, tables: list<array{name: string, count: string, path: string, current: bool}>, collections: list<array{name: string, count: string, path: string, current: bool}>, newCollectionPath: string|null, schemaPath: string, trashCount: string, trashPath: string, data: array{importPath: string, exportPath: string}|null}
     */
    public static function build(
        string $projectKey,
        string $projectName,
        array $tables,
        int $trashCount,
        ?string $currentTableKey,
        bool $canImportExport = false,
        array $collections = [],
        ?string $currentCollectionKey = null,
        ?string $newCollectionTableKey = null,
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

        $collectionItems = [];
        foreach ($collections as $collection) {
            if (
                !is_array($collection)
                || !is_string($collection['key'] ?? null)
                || !is_string($collection['name'] ?? null)
                || !is_int($collection['count'] ?? null)
            ) {
                throw new InvalidArgumentException('The sidebar lists collections as key, name, and count.');
            }
            $key = $collection['key'];
            if (preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException('Invalid collection key.');
            }
            $collectionItems[] = [
                'name' => $collection['name'],
                'count' => number_format(max(0, $collection['count'])),
                'path' => "/projects/{$projectKey}/collections/{$key}",
                'current' => $key === $currentCollectionKey,
            ];
        }
        $newCollectionPath = null;
        if ($newCollectionTableKey !== null) {
            if (preg_match(self::KEY, $newCollectionTableKey) !== 1) {
                throw new InvalidArgumentException('Invalid table key.');
            }
            $newCollectionPath = "/projects/{$projectKey}/tables/{$newCollectionTableKey}/collections/new";
        }

        return [
            'projectName' => $projectName,
            'switchPath' => '/projects',
            'tables' => $items,
            'collections' => $collectionItems,
            'newCollectionPath' => $newCollectionPath,
            'schemaPath' => "/projects/{$projectKey}/tables",
            'trashCount' => number_format(max(0, $trashCount)),
            'trashPath' => "/projects/{$projectKey}/trash",
            'data' => $canImportExport
                ? ['importPath' => "/projects/{$projectKey}/import", 'exportPath' => "/projects/{$projectKey}/export"]
                : null,
        ];
    }
}
