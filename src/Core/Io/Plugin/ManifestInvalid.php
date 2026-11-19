<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

/**
 * A plugin directory whose manifest or layout breaks a rule of M3 spec
 * section 4.1. The reason is one of REASONS (the plugins screen shows one
 * string per reason, `plugins.reason.<code>`); the message is plain text
 * for logs and tests only and never holds manifest contents.
 */
final class ManifestInvalid extends \RuntimeException
{
    public const MANIFEST_MISSING = 'manifest-missing';
    public const MANIFEST_LINK = 'manifest-link';
    public const TOO_LARGE = 'too-large';
    public const NOT_UTF8 = 'not-utf8';
    public const NOT_JSON = 'not-json';
    public const TOO_DEEP = 'too-deep';
    public const NOT_AN_OBJECT = 'not-an-object';
    public const UNKNOWN_MEMBER = 'unknown-member';
    public const MISSING_MEMBER = 'missing-member';
    public const BAD_API = 'bad-api';
    public const BAD_ID = 'bad-id';
    public const ID_MISMATCH = 'id-mismatch';
    public const KIND_MISMATCH = 'kind-mismatch';
    public const BAD_NAME = 'bad-name';
    public const BAD_DESCRIPTION = 'bad-description';
    public const BAD_VERSION = 'bad-version';
    public const BAD_CLASS = 'bad-class';
    public const BAD_LICENCE = 'bad-licence';
    public const BAD_EXTENSIONS = 'bad-extensions';
    public const BAD_MEDIA_TYPE = 'bad-media-type';
    public const BAD_SCOPES = 'bad-scopes';
    public const BAD_REQUIRES_EXTENSIONS = 'bad-requires-extensions';
    public const SRC_MISSING = 'src-missing';
    public const SRC_OUTSIDE = 'src-outside';
    public const CLASS_FILE_MISSING = 'class-file-missing';

    public const REASONS = [
        self::MANIFEST_MISSING, self::MANIFEST_LINK, self::TOO_LARGE, self::NOT_UTF8, self::NOT_JSON, self::TOO_DEEP,
        self::NOT_AN_OBJECT, self::UNKNOWN_MEMBER, self::MISSING_MEMBER, self::BAD_API, self::BAD_ID, self::ID_MISMATCH,
        self::KIND_MISMATCH, self::BAD_NAME, self::BAD_DESCRIPTION, self::BAD_VERSION, self::BAD_CLASS, self::BAD_LICENCE,
        self::BAD_EXTENSIONS, self::BAD_MEDIA_TYPE, self::BAD_SCOPES, self::BAD_REQUIRES_EXTENSIONS, self::SRC_MISSING,
        self::SRC_OUTSIDE, self::CLASS_FILE_MISSING,
    ];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \LogicException('Unknown manifest problem.');
        }
        parent::__construct('Plugin manifest problem: ' . $reason);
    }
}
