<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * Form format 1 (`docs/formats/tiffin.md` section 12.1, ADR 0014
 * decision 1): its version number and limits.
 */
final class FormFormat
{
    public const VERSION = 1;
    public const MAX_BYTES = 262144;
    public const MAX_ELEMENTS = 5000;
    public const MAX_DEPTH = 8;

    public const MAX_LABEL = 60;
    public const MAX_LEGEND = 100;
    public const MAX_ALT = 200;
    public const MAX_COLUMN = 12;
    public const MAX_ROW = 50;

    private function __construct()
    {
    }
}
