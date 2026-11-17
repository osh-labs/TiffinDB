<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

/**
 * Who may reach a route (M1 spec part 2 section 2.3); the kernel's gate
 * applies it (section 2.4).
 */
enum AccessLevel: string
{
    /** Only before installation is complete. */
    case Setup = 'setup';
    /** Installed; a signed-in person is redirected to /projects. */
    case Guest = 'guest';
    /** Installed and signed in. */
    case User = 'user';
    /** The kernel applies no gate (only "/"). */
    case Any = 'any';
}
