<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Shared import and export strings (M3 spec part 2 section 6;
 * docs/design/m3/import.md section 1): the steps partial's visible prefix
 * (steps.done, steps.now, steps.next, printed by the partial) and the
 * import step labels (steps.import.*, passed to the partial by the import
 * screens and built at run time). Screen-specific strings live in each
 * screen package's own file.
 */

declare(strict_types=1);

return [
    'steps.done' => 'Step {n}, done',
    'steps.now' => 'Step {n}, now',
    'steps.next' => 'Step {n}',

    'steps.import.label' => 'Import steps',
    'steps.import.choose' => 'Choose a file',
    'steps.import.check' => 'Check the fields',
    'steps.import.import' => 'Import',
    'steps.import.report' => 'Read the report',
];
