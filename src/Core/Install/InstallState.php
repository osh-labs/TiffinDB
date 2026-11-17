<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** Where an installation is (M1 spec section 10.1). */
enum InstallState
{
    /** No candidate data directory holds system.sqlite. */
    case NeedsDataFolder;
    /** system.sqlite exists; meta installed_at is absent. */
    case NeedsAdmin;
    /** meta installed_at is present. */
    case Installed;
}
