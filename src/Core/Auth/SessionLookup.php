<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/**
 * The result of Sessions::lookup(), a plain read (P69): a live session
 * that needs no write, or a sign that resolve() must run inside write()
 * (to touch or delete the row), or neither (no such session).
 */
final class SessionLookup
{
    private function __construct(public readonly ?SessionRecord $record, public readonly bool $needsWrite)
    {
    }

    public static function fresh(SessionRecord $record): self
    {
        return new self($record, false);
    }

    public static function needsWrite(): self
    {
        return new self(null, true);
    }

    public static function absent(): self
    {
        return new self(null, false);
    }
}
