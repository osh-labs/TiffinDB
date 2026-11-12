<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Schema;

/** How many records one side of a relation may link to (`_tiffin_relations.left_max` and `right_max`). */
enum Cardinality: string
{
    case One = 'one';
    case Many = 'many';
}
