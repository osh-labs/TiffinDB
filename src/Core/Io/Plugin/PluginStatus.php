<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

/** The state of a discovered plugin (M3 spec section 4.2). Only an Enabled plugin is ever loaded. */
enum PluginStatus: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Unavailable = 'unavailable';
    case Broken = 'broken';
}
