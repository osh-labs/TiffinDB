<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Committed default configuration. No secrets ever go in this directory.
 * Installation-specific settings (data_dir, timezone, secure_cookies) go in
 * the optional, git-ignored config/local.php (M1 spec part 2 section 2.9).
 */

declare(strict_types=1);

return [
    // Application version; used in asset URLs.
    'version' => '0.1.0-dev',
    // Target of the footer "Source code" link (AGPL section 13).
    'source_url' => 'https://github.com/osh-labs/tiffindb',
    // The M0 query-parameter appearance mechanism, for development only.
    // Off from M1: appearance is stored per person.
    'appearance_preview' => false,
];
