<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** One value of a choice setting, with its label (plain text shown escaped, P133). */
final class SettingChoice
{
    public function __construct(public readonly string $value, public readonly string $label)
    {
        if (!Values::isText($value, 1, 80) || !Values::isText($label, 1, 80)) {
            throw new \InvalidArgumentException('A setting choice has a value and a label of 1 to 80 characters.');
        }
    }
}
