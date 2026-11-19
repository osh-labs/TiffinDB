<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

/**
 * One discovered plugin (M3 spec section 4.2). The manifest is null only
 * for a Broken plugin; Broken plugins carry a reason code
 * (ManifestInvalid::REASONS or a PluginScanner::REASON_* code), and
 * Unavailable ones the PHP extensions that are not loaded.
 */
final class PluginInfo
{
    /** @param list<string> $missingExtensions */
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly ?Manifest $manifest,
        public readonly PluginStatus $status,
        public readonly bool $builtIn,
        public readonly ?string $brokenReason,
        public readonly array $missingExtensions,
    ) {
    }

    /** '<kind>/<id>' */
    public function ref(): string
    {
        return $this->kind . '/' . $this->id;
    }
}
