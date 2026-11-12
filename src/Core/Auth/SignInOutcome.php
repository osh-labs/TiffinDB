<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** The outcome of a sign-in. Invalid covers every failure reason alike (no user enumeration). */
enum SignInOutcome
{
    case Success;
    case Invalid;
    case Throttled;
}
