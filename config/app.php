<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Committed default configuration. No secrets ever go in this directory;
 * installation-specific settings arrive with the installer (M1).
 */

declare(strict_types=1);

return [
    // Application version; used in asset URLs.
    'version' => '0.1.0-dev',
    // Target of the footer "Source code" link (AGPL section 13).
    'source_url' => 'https://github.com/osh-labs/tiffindb',
    // Enables the M0 query-parameter appearance mechanism. Set to false in M1.
    'appearance_preview' => true,
];
