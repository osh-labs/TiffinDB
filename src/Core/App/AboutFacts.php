<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * What the About page shows (M7 spec section 10): the configured version,
 * the third-party components of `config/notices.json`, the plugins, and,
 * for Admins only, the server's PHP and SQLite versions (null otherwise).
 */
final class AboutFacts
{
    /**
     * @param list<AboutComponent> $components
     * @param list<AboutPlugin> $plugins
     * @param array{php: string, sqlite: string}|null $server
     */
    public function __construct(
        public readonly string $version,
        public readonly array $components,
        public readonly array $plugins,
        public readonly ?array $server,
    ) {
    }
}
