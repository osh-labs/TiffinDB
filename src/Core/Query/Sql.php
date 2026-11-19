<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/**
 * A fragment of SQL with its bound values (`docs/specs/M2-records.md`
 * section 3.9, ADR 0013 decision 9.10). This is the only file under
 * src/Core/ that joins variable text into SQL, and it only ever joins
 * texts of other fragments; tests/core/schema/SqlStringsTest.php exempts
 * it by name and requires every Sql::of() text elsewhere to be built from
 * string literals, Identifier calls, Database::placeholders(), and the
 * sql() of definition objects and of other fragments.
 *
 * Checks made here, at run time, whatever the caller:
 * - the text holds exactly one `?` per bound value, and no numbered
 *   (`?1`) or named (`:name`, `@name`, `$name`) parameter;
 * - the text holds no `;`, no comment opener (`--`, `/*`), and no NUL, so
 *   a fragment can never end a statement or hide the rest of one;
 * - bound values are null, int, string, bool, or finite float only;
 * - join() glues with one of four fixed separators.
 * Values are never written into the text; they reach SQLite as parameters.
 */
final class Sql
{
    public const GLUES = [' AND ', ' OR ', ', ', ' '];

    /** @param list<string|int|float|bool|null> $params */
    private function __construct(private readonly string $text, private readonly array $params)
    {
    }

    /**
     * @param list<string|int|float|bool|null> $params
     * @throws \InvalidArgumentException when the text or the values break a rule above
     */
    public static function of(string $text, array $params = []): self
    {
        if ($text === '' || str_contains($text, "\0") || str_contains($text, ';') || str_contains($text, '--') || str_contains($text, '/*')) {
            throw new \InvalidArgumentException('An SQL fragment must be non-empty and hold no NUL, semicolon, or comment.');
        }
        if (preg_match('/\?\d|[:@$][A-Za-z_]/', $text) === 1) {
            throw new \InvalidArgumentException('An SQL fragment uses plain ? placeholders only.');
        }
        if (!array_is_list($params)) {
            throw new \InvalidArgumentException('Bound values must be a list.');
        }
        foreach ($params as $value) {
            if (!($value === null || is_int($value) || is_string($value) || is_bool($value) || (is_float($value) && is_finite($value)))) {
                throw new \InvalidArgumentException('A bound value must be null, an int, a string, a bool, or a finite float.');
            }
        }
        if (substr_count($text, '?') !== count($params)) {
            throw new \InvalidArgumentException('An SQL fragment needs exactly one bound value per placeholder.');
        }

        return new self($text, $params);
    }

    /**
     * The fragments' texts joined with $glue, and their values in the same order.
     *
     * @param list<self> $parts non-empty
     */
    public static function join(string $glue, array $parts): self
    {
        if (!in_array($glue, self::GLUES, true)) {
            throw new \InvalidArgumentException('Fragments are joined with AND, OR, a comma, or a space only.');
        }
        if ($parts === [] || !array_is_list($parts)) {
            throw new \InvalidArgumentException('Join needs a non-empty list of fragments.');
        }
        $texts = [];
        $params = [];
        foreach ($parts as $part) {
            if (!$part instanceof self) {
                throw new \InvalidArgumentException('Only SQL fragments can be joined.');
            }
            $texts[] = $part->text;
            $params = [...$params, ...$part->params];
        }

        return new self(implode($glue, $texts), $params);
    }

    /** The fragment in parentheses. */
    public function wrap(): self
    {
        return new self('(' . $this->text . ')', $this->params);
    }

    public function sql(): string
    {
        return $this->text;
    }

    /** @return list<string|int|float|bool|null> */
    public function params(): array
    {
        return $this->params;
    }
}
