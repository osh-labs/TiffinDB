<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Timestamp;

/**
 * Saved collections in `_tiffin_collections` (`docs/formats/tiffin.md`
 * section 4.8, `docs/specs/M4-core.md` section 6.2). The store never
 * interprets rules: it stores what CollectionRules validated, and reads
 * what it can (a damaged row is read with the rules it can read and
 * logged by key, never an error).
 */
final class CollectionStore
{
    public const MAX_COLLECTIONS = 50;
    public const MAX_RULES = 10;
    public const MAX_NAME = 60;
    /**
     * Longest stored rules text read (P181, review L-5): ten rules of two
     * 200-character values each fit in far less. Longer text is not read
     * from the file (the SQL bound) nor decoded; the row is read with no
     * rules and logged, like any other damaged rules text.
     */
    public const MAX_RULES_BYTES = 65536;

    private const SORT_TOKEN = '/^(?:added\.(?:asc|desc)|changed\.desc|[a-z2-7]{12}\.(?:asc|desc))\z/';

    public function __construct(
        private readonly Database $db,
        private readonly Schema $schema,
        private readonly Keys $keys,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<Collection> every collection of the project, by position */
    public function all(): array
    {
        return self::read($this->db);
    }

    /** @return list<Collection> what all() returns, read from $db without a store */
    public static function read(Database $db): array
    {
        $collections = [];
        foreach ($db->all(
            'SELECT "key", "table_key", "name", "match_mode", CASE WHEN length(CAST("rules" AS BLOB)) <= ? THEN "rules" END AS "rules", "sort", "position", "created_at", "created_by", "updated_at", "updated_by"'
            . ' FROM "_tiffin_collections" ORDER BY "position", "key"',
            [self::MAX_RULES_BYTES],
        ) as $row) {
            $collection = self::fromRow($row);
            if ($collection !== null) {
                $collections[] = $collection;
            }
        }

        return $collections;
    }

    public function get(string $key): ?Collection
    {
        if (!Keys::isValid($key)) {
            return null;
        }
        $row = $this->db->one(
            'SELECT "key", "table_key", "name", "match_mode", CASE WHEN length(CAST("rules" AS BLOB)) <= ? THEN "rules" END AS "rules", "sort", "position", "created_at", "created_by", "updated_at", "updated_by"'
            . ' FROM "_tiffin_collections" WHERE "key" = ?',
            [self::MAX_RULES_BYTES, $key],
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Creates a collection at the end of the list; returns its key. Runs its own write.
     *
     * @param list<CollectionRule> $rules
     * @throws ValidationFailed for the name, too many rules, a taken name, or too many collections
     * @throws NotFound when the table does not exist
     */
    public function create(string $tableKey, string $name, MatchMode $match, array $rules, ?string $sort, string $userKey): string
    {
        $this->schema->table($tableKey);
        $name = self::checked($name, $rules, $sort);
        $encoded = self::encode($rules);

        return $this->db->write(function () use ($tableKey, $name, $match, $encoded, $sort, $userKey): string {
            if ($this->db->value('SELECT 1 FROM "_tiffin_tables" WHERE "key" = ?', [$tableKey]) === null) {
                throw new NotFound('No such table.');
            }
            if ((int) $this->db->value('SELECT COUNT(*) FROM "_tiffin_collections"') >= self::MAX_COLLECTIONS) {
                throw new ValidationFailed([new Problem(null, 'problem.collection.too_many', ['max' => self::MAX_COLLECTIONS])]);
            }
            $this->requireFreeName($name, null);
            $key = $this->newKey();
            $position = (int) $this->db->value('SELECT COALESCE(MAX("position"), 0) + 1 FROM "_tiffin_collections"');
            $now = Timestamp::format($this->clock->now());
            $this->db->run(
                'INSERT INTO "_tiffin_collections" ("key", "table_key", "name", "match_mode", "rules", "sort", "position", "created_at", "created_by", "updated_at", "updated_by")'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$key, $tableKey, $name, $match->value, $encoded, $sort, $position, $now, $userKey, $now, $userKey],
            );
            ProjectFile::touch($this->db, $userKey, $this->clock);

            return $key;
        });
    }

    /**
     * Changes the name, match mode, rules, and sort; the table and position stay.
     *
     * @param list<CollectionRule> $rules
     * @throws ValidationFailed for the name, too many rules, or a taken name
     * @throws NotFound when the collection does not exist
     */
    public function update(string $key, string $name, MatchMode $match, array $rules, ?string $sort, string $userKey): void
    {
        if (!Keys::isValid($key)) {
            throw new NotFound('No such collection.');
        }
        $name = self::checked($name, $rules, $sort);
        $encoded = self::encode($rules);
        $this->db->write(function () use ($key, $name, $match, $encoded, $sort, $userKey): void {
            if ($this->db->value('SELECT 1 FROM "_tiffin_collections" WHERE "key" = ?', [$key]) === null) {
                throw new NotFound('No such collection.');
            }
            $this->requireFreeName($name, $key);
            $this->db->run(
                'UPDATE "_tiffin_collections" SET "name" = ?, "match_mode" = ?, "rules" = ?, "sort" = ?, "updated_at" = ?, "updated_by" = ? WHERE "key" = ?',
                [$name, $match->value, $encoded, $sort, Timestamp::format($this->clock->now()), $userKey, $key],
            );
            ProjectFile::touch($this->db, $userKey, $this->clock);
        });
    }

    /**
     * Deletes the collection (its records are untouched). With $userKey,
     * also records who changed the project.
     *
     * @throws NotFound when the collection does not exist
     */
    public function delete(string $key, ?string $userKey = null): void
    {
        if (!Keys::isValid($key)) {
            throw new NotFound('No such collection.');
        }
        $this->db->write(function () use ($key, $userKey): void {
            if ($this->db->run('DELETE FROM "_tiffin_collections" WHERE "key" = ?', [$key]) !== 1) {
                throw new NotFound('No such collection.');
            }
            if ($userKey !== null) {
                ProjectFile::touch($this->db, $userKey, $this->clock);
            }
        });
    }

    /** Whether $sort is a sort token of the stored shape (section 4.8); says nothing of the field. */
    public static function isSortToken(mixed $sort): bool
    {
        return is_string($sort) && preg_match(self::SORT_TOKEN, $sort) === 1;
    }

    // ------------------------------------------------------------------ internals

    /**
     * The trimmed name, after the checks made before the write.
     *
     * @param list<CollectionRule> $rules
     */
    private static function checked(string $name, array $rules, ?string $sort): string
    {
        if (!array_is_list($rules)) {
            throw new \InvalidArgumentException('Rules must be a list.');
        }
        foreach ($rules as $rule) {
            if (!$rule instanceof CollectionRule) {
                throw new \InvalidArgumentException('Rules must be CollectionRule values.');
            }
        }
        if ($sort !== null && !self::isSortToken($sort)) {
            throw new \InvalidArgumentException('A sort is a sort token or null.');
        }
        $problems = Text::nameProblems($name, 'name', self::MAX_NAME);
        if (count($rules) > self::MAX_RULES) {
            $problems[] = new Problem(null, 'problem.collection.too_many_rules', ['max' => self::MAX_RULES]);
        }
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }

        return Text::trim($name);
    }

    /** Inside the write, before the unique index can refuse it. */
    private function requireFreeName(string $name, ?string $exceptKey): void
    {
        $taken = $this->db->value(
            'SELECT 1 FROM "_tiffin_collections" WHERE "name" = ? COLLATE NOCASE AND "key" IS NOT ?',
            [$name, $exceptKey],
        );
        if ($taken !== null) {
            throw new ValidationFailed([new Problem('name', 'problem.collection.name_taken')]);
        }
    }

    /** A key not used by any table, field, relation, form, or collection of the project, nor by any SQL object. */
    private function newKey(): string
    {
        return $this->keys->generateUnique(function (string $key): bool {
            $used = $this->db->value(
                'SELECT 1 FROM "_tiffin_tables" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_fields" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_relations" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_forms" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "_tiffin_collections" WHERE "key" = ?'
                . ' UNION ALL SELECT 1 FROM "sqlite_master" WHERE "name" IN (?, ?)',
                [$key, $key, $key, $key, $key, Identifier::tableName($key), Identifier::joinName($key)],
            );

            return $used !== null;
        });
    }

    /** @param list<CollectionRule> $rules */
    private static function encode(array $rules): string
    {
        return json_encode(
            array_map(static fn (CollectionRule $rule): array => $rule->toArray(), $rules),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): ?Collection
    {
        $key = $row['key'] ?? null;
        $tableKey = $row['table_key'] ?? null;
        $name = $row['name'] ?? null;
        if (!Keys::isValid($key) || !Keys::isValid($tableKey) || !is_string($name) || !is_string($row['created_at']) || !is_string($row['updated_at'])) {
            error_log('TiffinDB: a collection row could not be read and was skipped.');

            return null;
        }
        assert(is_string($key) && is_string($tableKey));
        $damaged = false;
        $match = MatchMode::tryFrom(is_string($row['match_mode']) ? $row['match_mode'] : '');
        if ($match === null) {
            $match = MatchMode::All;
            $damaged = true;
        }
        $rules = [];
        $text = is_string($row['rules']) && strlen($row['rules']) <= self::MAX_RULES_BYTES ? $row['rules'] : '';
        try {
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            $damaged = true;
            $decoded = [];
        }
        if (count($decoded) > self::MAX_RULES) {
            $damaged = true;
            $decoded = array_slice($decoded, 0, self::MAX_RULES);
        }
        foreach ($decoded as $entry) {
            try {
                $rules[] = CollectionRule::fromArray($entry);
            } catch (\InvalidArgumentException) {
                $damaged = true;
            }
        }
        $sort = self::isSortToken($row['sort']) ? $row['sort'] : null;
        if ($damaged) {
            error_log('TiffinDB: collection ' . $key . ' was read with the rules that could be read.');
        }

        return new Collection(
            $key,
            $tableKey,
            $name,
            $match,
            $rules,
            is_string($sort) ? $sort : null,
            (int) $row['position'],
            $row['created_at'],
            is_string($row['created_by']) ? $row['created_by'] : null,
            $row['updated_at'],
            is_string($row['updated_by']) ? $row['updated_by'] : null,
        );
    }
}
