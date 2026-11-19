<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * The view state of a record list as the query string carries it (M2 spec
 * section 4): search text, sort token, one filter condition, and the page.
 * The strings are raw: RecordOperations passes them to the record layer,
 * which refuses what is invalid, and returns a request rebuilt from the
 * normalised query for every link. Empty strings are held as null.
 */
final class ListRequest
{
    public const PARAMS = ['q', 'sort', 'filter', 'op', 'v', 'v2', 'page'];
    public const MAX_PAGE = 1000000;

    public function __construct(
        public readonly ?string $q,
        public readonly ?string $sort,
        public readonly ?string $filter,
        public readonly ?string $op,
        public readonly ?string $v,
        public readonly ?string $v2,
        public readonly int $page,
    ) {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('The page must be 1 to ' . self::MAX_PAGE . '.');
        }
    }

    /**
     * Reads the parameters from a query array. Non-string values are
     * ignored, and so are empty strings; `page` must be digits giving 1 to
     * MAX_PAGE, else it is 1.
     *
     * @param array<mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $page = 1;
        $text = $query['page'] ?? null;
        if (is_string($text) && preg_match('/^[0-9]{1,7}\z/', $text) === 1) {
            $number = (int) $text;
            if ($number >= 1 && $number <= self::MAX_PAGE) {
                $page = $number;
            }
        }

        return new self(
            self::text($query, 'q'),
            self::text($query, 'sort'),
            self::text($query, 'filter'),
            self::text($query, 'op'),
            self::text($query, 'v'),
            self::text($query, 'v2'),
            $page,
        );
    }

    public static function none(): self
    {
        return new self(null, null, null, null, null, null, 1);
    }

    /**
     * Query parameters for links, in PARAMS order; empty values and page 1
     * left out.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [];
        foreach (['q' => $this->q, 'sort' => $this->sort, 'filter' => $this->filter, 'op' => $this->op, 'v' => $this->v, 'v2' => $this->v2] as $name => $value) {
            if ($value !== null && $value !== '') {
                $query[$name] = $value;
            }
        }
        if ($this->page !== 1) {
            $query['page'] = (string) $this->page;
        }

        return $query;
    }

    public function withPage(int $page): self
    {
        return new self($this->q, $this->sort, $this->filter, $this->op, $this->v, $this->v2, $page);
    }

    public function withoutPage(): self
    {
        return $this->withPage(1);
    }

    public function withoutFilter(): self
    {
        return new self($this->q, $this->sort, null, null, null, null, 1);
    }

    public function withoutSearchAndFilter(): self
    {
        return new self(null, $this->sort, null, null, null, null, 1);
    }

    /** @param array<mixed> $query */
    private static function text(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
