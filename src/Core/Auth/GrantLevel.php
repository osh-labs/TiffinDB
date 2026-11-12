<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** The level of a project grant; v1 has only `edit` (ADR 0002, ADR 0011 decision 3). */
enum GrantLevel: string
{
    case Edit = 'edit';
}
