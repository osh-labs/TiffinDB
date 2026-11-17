<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema\Type;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;

/**
 * Private helper for normaliseOptions(): reads given options with their
 * defaults and collects every problem (field `options.<name>`) before
 * throwing ValidationFailed (M1 spec section 3.1). A missing or null option
 * takes its default.
 *
 * Integers are accepted as PHP ints or as decimal-digit strings (what a form
 * posts); booleans as PHP bools, 0 and 1, or the strings '0' and '1'.
 */
final class Options
{
    /** @var list<Problem> */
    private array $problems = [];

    /** @param array<string, mixed> $given */
    public function __construct(private readonly array $given)
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->given) && $this->given[$name] !== null;
    }

    public function raw(string $name): mixed
    {
        return $this->given[$name] ?? null;
    }

    /** An integer from $min to $max; otherwise problem.options.range (min, max) and the default. */
    public function int(string $name, int $default, int $min, int $max): int
    {
        if (!$this->has($name)) {
            return $default;
        }
        $value = self::toInt($this->given[$name]);
        if ($value === null || $value < $min || $value > $max) {
            $this->add($name, 'problem.options.range', ['min' => $min, 'max' => $max]);

            return $default;
        }

        return $value;
    }

    /** A boolean; otherwise problem.options.invalid and the default. */
    public function bool(string $name, bool $default): bool
    {
        if (!$this->has($name)) {
            return $default;
        }
        $value = $this->given[$name];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        $this->add($name, 'problem.options.invalid');

        return $default;
    }

    /** @param array<string, string|int> $params */
    public function add(string $name, string $key, array $params = []): void
    {
        $this->problems[] = new Problem('options.' . $name, $key, $params);
    }

    /**
     * @param array<string, mixed> $normalised
     * @return array<string, mixed>
     * @throws ValidationFailed when any problem was added
     */
    public function finish(array $normalised): array
    {
        if ($this->problems !== []) {
            throw new ValidationFailed($this->problems);
        }

        return $normalised;
    }

    /** An int from an int or a string of at most 18 ASCII digits with an optional minus sign. */
    public static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
