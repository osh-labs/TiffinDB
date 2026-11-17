<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** A person's role (ADR 0002, ADR 0011 decision 3). */
enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
}
