<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/**
 * A project file is missing, is not a TiffinDB project, has a newer format,
 * or does not match the registry; or the system database was written by a
 * newer version of TiffinDB (SYSTEM_NEWER, M7 spec part 1 section 12.2).
 */
final class Unavailable extends CoreException
{
    public const MISSING = 'missing';
    public const NOT_PROJECT = 'not-project';
    public const NEWER = 'newer';
    public const MISMATCH = 'mismatch';
    public const SYSTEM_NEWER = 'system-newer';

    private const REASONS = [self::MISSING, self::NOT_PROJECT, self::NEWER, self::MISMATCH, self::SYSTEM_NEWER];

    /** @param 'missing'|'not-project'|'newer'|'mismatch'|'system-newer' $reason */
    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown Unavailable reason.');
        }
        parent::__construct('Unavailable: ' . $reason);
    }
}
