<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * The lost-update token of a record (`docs/specs/M2-records.md` section
 * 3.5, ADR 0013 decision 6): the first 16 lower-case hexadecimal
 * characters of the SHA-256 of a JSON list of the stored row (every
 * column as [name, value], sorted by name) and the record's links (every
 * relation field as [field key, sorted ids of every linked record, live or
 * trashed], sorted by field key). Not a secret; it carries no data.
 */
final class RecordVersion
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row the row as SQLite returned it
     * @param array<string, list<int>> $links relation field key => linked ids on this record's side
     */
    public static function of(array $row, array $links): string
    {
        $columns = [];
        foreach ($row as $name => $value) {
            $columns[] = [(string) $name, $value];
        }
        usort($columns, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $linkPairs = [];
        foreach ($links as $fieldKey => $ids) {
            $ids = array_values($ids);
            sort($ids, SORT_NUMERIC);
            $linkPairs[] = [(string) $fieldKey, $ids];
        }
        usort($linkPairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $json = json_encode(
            self::pair($columns, $linkPairs),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return substr(hash('sha256', $json), 0, 16);
    }

    /**
     * @param list<mixed> $columns
     * @param list<mixed> $links
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    private static function pair(array $columns, array $links): array
    {
        return [$columns, $links];
    }
}
