<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/**
 * What the requirement check reads from the running PHP (M1 spec section
 * 10.2). ServerEnvironment reads the real values; tests pass a fake.
 */
interface RequirementEnvironment
{
    public function phpVersionId(): int;

    public function phpVersion(): string;

    public function extensionLoaded(string $name): bool;

    /** The SQLite library version, or null when pdo_sqlite is missing. */
    public function sqliteVersion(): ?string;

    /** Whether secure random bytes can be made. */
    public function randomWorks(): bool;
}
