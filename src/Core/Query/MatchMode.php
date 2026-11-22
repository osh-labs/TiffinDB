<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** Whether a record must match all rules of a collection or any of them (ADR 0019 decision 2). */
enum MatchMode: string
{
    case All = 'all';
    case Any = 'any';
}
