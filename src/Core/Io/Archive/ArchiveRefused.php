<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

/**
 * A project archive that cannot be imported (M3 spec sections 11.3 to
 * 11.5). The reason is one of the codes below; Core\App maps each to its
 * message key `problem.archive.<reason with - replaced by _>` (section
 * 14) with a literal table. The message never holds an entry name, a
 * value, or a path from the archive; the cause, when there is one, is
 * kept as the previous exception for logs.
 */
final class ArchiveRefused extends \RuntimeException
{
    public const ENTRY_NAME = 'entry-name';
    public const DUPLICATE = 'duplicate';
    public const ENCRYPTED = 'encrypted';
    public const COMPRESSION = 'compression';
    public const TOO_LARGE = 'too-large';
    public const MISSING_ENTRY = 'missing-entry';
    public const MANIFEST = 'manifest';
    public const NOT_SQLITE = 'not-sqlite';
    public const DAMAGED = 'damaged';
    public const NOT_PROJECT = 'not-project';
    public const NEWER = 'newer';
    public const UNEXPECTED_OBJECT = 'unexpected-object';
    public const BAD_METADATA = 'bad-metadata';
    public const BAD_TABLE = 'bad-table';
    public const REBUILD_FAILED = 'rebuild-failed';

    /** Every reason, in the order of section 14. */
    public const REASONS = [
        self::ENTRY_NAME, self::DUPLICATE, self::ENCRYPTED, self::COMPRESSION, self::TOO_LARGE, self::MISSING_ENTRY,
        self::MANIFEST, self::NOT_SQLITE, self::DAMAGED, self::NOT_PROJECT, self::NEWER, self::UNEXPECTED_OBJECT,
        self::BAD_METADATA, self::BAD_TABLE, self::REBUILD_FAILED,
    ];

    /**
     * @param string $rule which check failed, for logs only (a fixed text
     *     written in this namespace, never content from the archive)
     */
    public function __construct(public readonly string $reason, public readonly string $rule = '', ?\Throwable $previous = null)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unknown archive refusal reason.');
        }
        parent::__construct('Archive refused: ' . $reason . ($rule === '' ? '' : ' (' . $rule . ')'), 0, $previous);
    }
}
