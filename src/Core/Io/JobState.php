<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io;

/** The state of an import job (M3 spec section 5.2). */
enum JobState: string
{
    case Reading = 'reading';
    case Review = 'review';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Done = 'done';
    case Failed = 'failed';

    /** Whether a job in this state may move to $to. Done and Failed are final. */
    public function canMoveTo(self $to): bool
    {
        if ($to === self::Failed) {
            return $this !== self::Done && $this !== self::Failed;
        }

        return in_array($to, match ($this) {
            self::Reading => [self::Review, self::Reading],
            self::Review => [self::Preparing, self::Reading, self::Review],
            self::Preparing => [self::Ready, self::Review, self::Reading, self::Preparing],
            self::Ready => [self::Done, self::Review, self::Reading, self::Preparing],
            self::Done, self::Failed => [],
        }, true);
    }
}
