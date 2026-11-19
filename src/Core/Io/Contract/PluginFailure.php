<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Contract;

/**
 * An expected failure of a plugin (M3 spec section 3.1). The reason is one
 * of the REASON_* constants (any other value is read as REASON_FAILED); the
 * detail is plain text for the server log only and is never shown.
 */
final class PluginFailure extends \RuntimeException
{
    public const REASON_UNREADABLE = 'unreadable';
    public const REASON_TOO_LARGE = 'too-large';
    public const REASON_UNSUPPORTED = 'unsupported';
    public const REASON_FAILED = 'failed';
    public const REASONS = [self::REASON_UNREADABLE, self::REASON_TOO_LARGE, self::REASON_UNSUPPORTED, self::REASON_FAILED];

    public readonly string $reason;

    public function __construct(string $reason, string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct($detail, 0, $previous);
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : self::REASON_FAILED;
    }
}
