<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Storage\SecureRandom;
use TiffinDB\Core\Storage\Sqlite;

/** The requirement environment of the running PHP (the default of section 10.2). */
final class ServerEnvironment implements RequirementEnvironment
{
    public function phpVersionId(): int
    {
        return PHP_VERSION_ID;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function sqliteVersion(): ?string
    {
        try {
            return Sqlite::libraryVersion();
        } catch (\Throwable) {
            return null;
        }
    }

    public function randomWorks(): bool
    {
        try {
            return strlen((new SecureRandom())->bytes(16)) === 16;
        } catch (\Throwable) {
            return false;
        }
    }
}
