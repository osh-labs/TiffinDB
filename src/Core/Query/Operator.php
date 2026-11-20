<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** Filter and rule operators (ADR 0013 decision 4, ADR 0019 decision 3; `docs/specs/M4-core.md` section 5.1). */
enum Operator: string
{
    case Is = 'is';
    case Empty = 'empty';
    case Between = 'between';
    case LinksTo = 'links-to';
    case Contains = 'contains';
    case NotEmpty = 'not-empty';
    case Less = 'less';
    case More = 'more';
    case On = 'on';
    case Before = 'before';
    case After = 'after';
}
