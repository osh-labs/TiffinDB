<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/** An export plugin (M3 spec section 3.3, contract API 1). Expected failures are PluginFailure. */
interface Exporter
{
    /** Options shown on the export page for this scope ('table' or 'project'). */
    public function settings(string $scope): Settings;

    /** Writes the export of $source to $targetPath (a new file in the job folder). */
    public function write(ExportSource $source, Settings $settings, string $targetPath): void;
}
