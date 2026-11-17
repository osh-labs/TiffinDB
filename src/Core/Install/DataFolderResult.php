<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** What prepareDataFolder() did (M1 spec section 10.3). */
final class DataFolderResult
{
    public const SAFE = 'safe';
    public const EXPOSED = 'exposed';
    public const INCONCLUSIVE = 'inconclusive';
    public const FAILED = 'failed';

    public const NO_PLACE = 'setup.data.no-place';
    public const NOT_EMPTY = 'setup.data.not-empty';

    public function __construct(
        public readonly string $outcome,
        public readonly string $label,
        public readonly bool $inTree,
        public readonly ?string $failureKey = null,
    ) {
        if (!in_array($outcome, [self::SAFE, self::EXPOSED, self::INCONCLUSIVE, self::FAILED], true)) {
            throw new \InvalidArgumentException('Unknown data folder outcome.');
        }
        if (($outcome === self::FAILED) !== ($failureKey !== null)) {
            throw new \InvalidArgumentException('A failure key goes with the failed outcome only.');
        }
    }
}
