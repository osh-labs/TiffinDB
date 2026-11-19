<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** Filter operators (ADR 0013 decision 4). */
enum Operator: string
{
    case Is = 'is';
    case Empty = 'empty';
    case Between = 'between';
    case LinksTo = 'links-to';
}
