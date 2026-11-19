<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** The settings of one plugin call, in display order, with distinct names (M3 spec section 3.1). */
final class Settings
{
    /** @param list<Setting> $items */
    public function __construct(public readonly array $items)
    {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Settings are a list.');
        }
        $names = [];
        foreach ($items as $item) {
            if (!$item instanceof Setting || isset($names[$item->name])) {
                throw new \InvalidArgumentException('Settings are Setting values with distinct names.');
            }
            $names[$item->name] = true;
        }
    }

    public function value(string $name): ?string
    {
        foreach ($this->items as $item) {
            if ($item->name === $name) {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * A copy with the given values applied; unknown names and values not
     * allowed by a setting (including any value that is not a string) are
     * ignored.
     *
     * @param array<mixed> $values name => value
     */
    public function with(array $values): self
    {
        $items = [];
        foreach ($this->items as $item) {
            $given = $values[$item->name] ?? null;
            $items[] = $item->allows($given) && is_string($given) ? $item->withValue($given) : $item;
        }

        return new self($items);
    }

    /** @return array<string, string> name => value */
    public function values(): array
    {
        $values = [];
        foreach ($this->items as $item) {
            $values[$item->name] = $item->value;
        }

        return $values;
    }
}
