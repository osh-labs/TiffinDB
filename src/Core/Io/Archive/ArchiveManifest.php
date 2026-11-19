<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Archive;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The manifest `tiffindb-archive.json` (`docs/formats/tiffin.md` section
 * 13.2): exactly nine members, written in the documented order.
 */
final class ArchiveManifest
{
    public const FORMAT = 'tiffindb-archive';
    public const VERSION = 1;
    /** Largest manifest read (M3 spec section 11.3 step 4). */
    public const MAX_BYTES = 16384;
    /** The members, in the order they are written. */
    public const MEMBERS = [
        'format', 'archive_version', 'created_at', 'created_with', 'project_format_version', 'project_name',
        'project_size', 'project_sha256', 'attachments',
    ];
    /** Longest `created_with` accepted (a version text such as `0.3.0-dev`). */
    public const MAX_CREATED_WITH = 40;
    /** Longest project name accepted; the registry's own limit is checked again at registration. */
    public const MAX_PROJECT_NAME = 200;

    private const SHA256 = '/^[0-9a-f]{64}\z/';
    private const CREATED_WITH = '/^[0-9A-Za-z.+_-]{1,40}\z/';
    /** The SHA-256 of no bytes, the placeholder hash of facts(). */
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(
        public readonly string $createdAt,
        public readonly string $createdWith,
        public readonly int $projectFormatVersion,
        public readonly string $projectName,
        public readonly int $projectSize,
        public readonly string $projectSha256,
        public readonly int $attachments,
    ) {
        if (preg_match(Timestamp::PATTERN, $createdAt) !== 1 || preg_match(self::CREATED_WITH, $createdWith) !== 1) {
            throw new \InvalidArgumentException('The creation time or version is not in the documented form.');
        }
        if ($projectFormatVersion < 1 || $projectSize < 0 || $attachments < 0 || preg_match(self::SHA256, $projectSha256) !== 1) {
            throw new \InvalidArgumentException('The project facts are not in the documented form.');
        }
    }

    /**
     * What the caller knows before the copy exists; ArchiveWriter fills in
     * the format version, size, hash, and attachment count.
     */
    public static function facts(string $createdAt, string $createdWith, string $projectName): self
    {
        return new self($createdAt, $createdWith, ProjectFile::VERSION, $projectName, 0, self::EMPTY_SHA256, 0);
    }

    /** This manifest with the facts of the copied database. */
    public function withProject(int $formatVersion, int $size, string $sha256, int $attachments): self
    {
        return new self($this->createdAt, $this->createdWith, $formatVersion, $this->projectName, $size, $sha256, $attachments);
    }

    public function toJson(): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'archive_version' => self::VERSION,
            'created_at' => $this->createdAt,
            'created_with' => $this->createdWith,
            'project_format_version' => $this->projectFormatVersion,
            'project_name' => $this->projectName,
            'project_size' => $this->projectSize,
            'project_sha256' => $this->projectSha256,
            'attachments' => $this->attachments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Reads a manifest from an archive (M3 spec section 11.3 step 4): a
     * JSON object with exactly MEMBERS, each of the documented type and
     * form. ArchiveRefused('manifest') for anything else, and ('newer')
     * when the archive or project format version is a whole number above
     * what this version of TiffinDB reads.
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES || !Text::isUtf8($json)) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest size or encoding');
        }
        try {
            $object = json_decode($json, false, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest json', $error);
        }
        if (!$object instanceof \stdClass) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest object');
        }
        if (self::hasDuplicateMember($json)) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest member twice');
        }
        $members = get_object_vars($object);
        $names = array_keys($members);
        $expected = self::MEMBERS;
        sort($names, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($names !== $expected) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest members');
        }
        if ($members['format'] !== self::FORMAT) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest format');
        }
        $archiveVersion = $members['archive_version'];
        $formatVersion = $members['project_format_version'];
        if (is_int($archiveVersion) && $archiveVersion > self::VERSION) {
            throw new ArchiveRefused(ArchiveRefused::NEWER, 'archive version');
        }
        if (is_int($formatVersion) && $formatVersion > ProjectFile::VERSION) {
            throw new ArchiveRefused(ArchiveRefused::NEWER, 'project format version');
        }
        if ($archiveVersion !== self::VERSION || !is_int($formatVersion) || $formatVersion < 1) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest versions');
        }
        $createdAt = $members['created_at'];
        $createdWith = $members['created_with'];
        $name = $members['project_name'];
        $size = $members['project_size'];
        $sha256 = $members['project_sha256'];
        $attachments = $members['attachments'];
        if (!is_string($createdAt) || !self::isTimestamp($createdAt) || !is_string($createdWith) || preg_match(self::CREATED_WITH, $createdWith) !== 1) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest creation');
        }
        if (!is_string($name) || Text::nameProblems($name, 'name', self::MAX_PROJECT_NAME) !== []) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest name');
        }
        if (!is_int($size) || $size < 0 || !is_string($sha256) || preg_match(self::SHA256, $sha256) !== 1 || !is_int($attachments) || $attachments < 0) {
            throw new ArchiveRefused(ArchiveRefused::MANIFEST, 'manifest project facts');
        }

        return new self($createdAt, $createdWith, $formatVersion, Text::trim($name), $size, $sha256, $attachments);
    }

    /**
     * Whether a member name appears twice (json_decode keeps the last one
     * silently). The text has already decoded at depth 2 as one object of
     * scalars, so every string followed by `:` is a member name of it.
     */
    private static function hasDuplicateMember(string $json): bool
    {
        // Strings and the text between them, in order from the start, so a quote inside a string never starts a token.
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[^"]+/s', $json, $matches) === false) {
            return true;
        }
        $tokens = $matches[0];
        $seen = [];
        foreach ($tokens as $i => $token) {
            if (!str_starts_with($token, '"') || !str_starts_with(ltrim($tokens[$i + 1] ?? ''), ':')) {
                continue;
            }
            $name = json_decode($token, false, 2);
            if (!is_string($name) || isset($seen[$name])) {
                return true;
            }
            $seen[$name] = true;
        }

        return false;
    }

    private static function isTimestamp(string $value): bool
    {
        try {
            Timestamp::parse($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
