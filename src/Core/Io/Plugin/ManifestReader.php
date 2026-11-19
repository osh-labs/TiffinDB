<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Plugin;

use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * Reads and validates `plugins/<kind>/<id>/plugin.json` (M3 spec section
 * 4.1, ADR 0015 decision 2). Security-critical: a manifest is untrusted
 * data. Reading one runs no plugin code and loads no class: the file is
 * read as bytes (never included), decoded as JSON with a depth limit, and
 * every member is checked against a fixed allowlist of names, types, and
 * patterns. The class name can only name a class directly inside the
 * plugin's own namespace, and the file that would hold it must be a
 * regular file inside the plugin's own `src/` directory.
 *
 * Every violation throws ManifestInvalid with a reason code. Checks run in
 * a fixed order, so a manifest with several problems reports the first:
 * the file, the JSON, unknown members, missing members, then each member
 * in the order of MEMBERS, then the layout.
 */
final class ManifestReader
{
    public const FILE = 'plugin.json';
    public const MAX_BYTES = 16384;
    public const MAX_DEPTH = 8;

    /** Members of every manifest, in check order. */
    public const MEMBERS = ['api', 'id', 'kind', 'name', 'version', 'class', 'licence', 'extensions', 'requires_extensions', 'description'];
    /** Members only an export manifest has (and must have). */
    public const EXPORT_MEMBERS = ['media_type', 'scopes'];

    /** The licences of the CLAUDE.md allowlist, as SPDX identifiers. */
    public const LICENCES = [
        'MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', 'LGPL-2.1-or-later', 'LGPL-3.0-or-later', 'MPL-2.0',
        'GPL-3.0-or-later', 'AGPL-3.0-or-later', 'AGPL-3.0-only', 'GPL-3.0-only',
    ];
    public const SCOPES = ['table', 'project'];

    public const NAME_MAX = 60;
    public const DESCRIPTION_MAX = 200;
    public const MAX_EXTENSIONS = 10;
    public const MAX_REQUIRED_EXTENSIONS = 20;
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\z/';
    private const EXTENSION_PATTERN = '/^[a-z0-9]{1,10}\z/';
    private const MEDIA_TYPE_PATTERN = '/^[a-z]+\/[a-z0-9.+-]{1,100}\z/';
    private const PHP_EXTENSION_PATTERN = '/^[a-z0-9_]{1,32}\z/';

    /**
     * @param string $pluginDir the plugin directory, `<plugins root>/<kind>/<dirName>`
     * @param string $kind `import` or `export`, from the directory the plugin is in
     * @param string $dirName the directory's own name
     * @throws ManifestInvalid
     */
    public function read(string $pluginDir, string $kind, string $dirName): Manifest
    {
        if (!Manifest::isValidRef($kind, $dirName)) {
            throw new ManifestInvalid(ManifestInvalid::BAD_ID);
        }
        if (!Fs::isDir($pluginDir)) {
            throw new ManifestInvalid(ManifestInvalid::MANIFEST_MISSING);
        }
        $data = self::decode(self::readFile($pluginDir . '/' . self::FILE));
        $manifest = self::validate($data, $kind, $dirName);
        self::checkLayout($pluginDir, $manifest);

        return $manifest;
    }

    /** The manifest's bytes: a regular file (never a link), at most MAX_BYTES. */
    private static function readFile(string $path): string
    {
        if (Fs::isFile($path) === false) {
            throw new ManifestInvalid(Fs::exists($path) && is_link($path) ? ManifestInvalid::MANIFEST_LINK : ManifestInvalid::MANIFEST_MISSING);
        }
        if (Fs::size($path) > self::MAX_BYTES) {
            throw new ManifestInvalid(ManifestInvalid::TOO_LARGE);
        }
        try {
            $bytes = ErrorTrap::run(
                static fn (): string|false => file_get_contents($path, false, null, 0, self::MAX_BYTES + 1),
                'Cannot read the plugin manifest.',
            );
        } catch (\RuntimeException) {
            throw new ManifestInvalid(ManifestInvalid::MANIFEST_MISSING);
        }
        if (!is_string($bytes)) {
            throw new ManifestInvalid(ManifestInvalid::MANIFEST_MISSING);
        }
        // The size is checked again on what was read: the file may have grown since the stat.
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ManifestInvalid(ManifestInvalid::TOO_LARGE);
        }

        return $bytes;
    }

    /** @return array<string, mixed> */
    private static function decode(string $bytes): array
    {
        if (!Text::isUtf8($bytes)) {
            throw new ManifestInvalid(ManifestInvalid::NOT_UTF8);
        }
        try {
            $data = json_decode($bytes, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ManifestInvalid($error->getCode() === JSON_ERROR_DEPTH ? ManifestInvalid::TOO_DEEP : ManifestInvalid::NOT_JSON);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ManifestInvalid(ManifestInvalid::NOT_AN_OBJECT);
        }
        // An object in the file: a JSON array decodes to a list, a scalar to no array. Numeric member
        // names ("0") decode to integer keys, which no allowed member has.
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new ManifestInvalid(ManifestInvalid::UNKNOWN_MEMBER);
            }
        }
        if ($data === [] && !str_starts_with(ltrim($bytes, " \t\n\r"), '{')) {
            throw new ManifestInvalid(ManifestInvalid::NOT_AN_OBJECT);
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function validate(array $data, string $kind, string $dirName): Manifest
    {
        $members = $kind === 'export' ? [...self::MEMBERS, ...self::EXPORT_MEMBERS] : self::MEMBERS;
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $members, true)) {
                throw new ManifestInvalid(ManifestInvalid::UNKNOWN_MEMBER);
            }
        }
        foreach ($members as $member) {
            if (!array_key_exists($member, $data)) {
                throw new ManifestInvalid(ManifestInvalid::MISSING_MEMBER);
            }
        }

        if ($data['api'] !== Manifest::API) {
            throw new ManifestInvalid(ManifestInvalid::BAD_API);
        }
        $id = $data['id'];
        if (!is_string($id) || preg_match(Manifest::ID_PATTERN, $id) !== 1) {
            throw new ManifestInvalid(ManifestInvalid::BAD_ID);
        }
        if ($id !== $dirName) {
            throw new ManifestInvalid(ManifestInvalid::ID_MISMATCH);
        }
        if ($data['kind'] !== $kind) {
            throw new ManifestInvalid(ManifestInvalid::KIND_MISMATCH);
        }
        $name = self::text($data['name'], 1, self::NAME_MAX, ManifestInvalid::BAD_NAME);
        $version = $data['version'];
        if (!is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new ManifestInvalid(ManifestInvalid::BAD_VERSION);
        }
        $class = $data['class'];
        if (!is_string($class) || Manifest::shortClass($class, $kind, $id) === null) {
            throw new ManifestInvalid(ManifestInvalid::BAD_CLASS);
        }
        if (!is_string($data['licence']) || !in_array($data['licence'], self::LICENCES, true)) {
            throw new ManifestInvalid(ManifestInvalid::BAD_LICENCE);
        }
        $extensions = self::stringList($data['extensions'], 1, $kind === 'export' ? 1 : self::MAX_EXTENSIONS, self::EXTENSION_PATTERN, ManifestInvalid::BAD_EXTENSIONS);
        $required = self::stringList($data['requires_extensions'], 0, self::MAX_REQUIRED_EXTENSIONS, self::PHP_EXTENSION_PATTERN, ManifestInvalid::BAD_REQUIRES_EXTENSIONS);
        $description = self::text($data['description'], 0, self::DESCRIPTION_MAX, ManifestInvalid::BAD_DESCRIPTION);

        $mediaType = null;
        $scopes = [];
        if ($kind === 'export') {
            $mediaType = $data['media_type'];
            if (!is_string($mediaType) || preg_match(self::MEDIA_TYPE_PATTERN, $mediaType) !== 1) {
                throw new ManifestInvalid(ManifestInvalid::BAD_MEDIA_TYPE);
            }
            $scopes = self::stringList($data['scopes'], 1, count(self::SCOPES), '/^(table|project)\z/', ManifestInvalid::BAD_SCOPES);
        }

        return new Manifest(Manifest::API, $id, $kind, $name, $version, $class, $data['licence'], $extensions, $mediaType, $scopes, $required, $description);
    }

    /** Plain text, trimmed, $min to $max characters, no control characters. */
    private static function text(mixed $value, int $min, int $max, string $reason): string
    {
        if (!is_string($value) || Text::hasControl($value)) {
            throw new ManifestInvalid($reason);
        }
        $trimmed = Text::trim($value);
        $length = Text::length($trimmed);
        if ($length < $min || $length > $max) {
            throw new ManifestInvalid($reason);
        }

        return $trimmed;
    }

    /**
     * A JSON array of $min to $max distinct strings matching $pattern.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value, int $min, int $max, string $pattern, string $reason): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new ManifestInvalid($reason);
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1 || in_array($item, $items, true)) {
                throw new ManifestInvalid($reason);
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * `src/` is a real directory (not a link) inside the plugin directory,
     * and `src/<ClassName>.php` a regular file (not a link) inside `src/`.
     */
    private static function checkLayout(string $pluginDir, Manifest $manifest): void
    {
        $src = $pluginDir . '/src';
        if (!Fs::exists($src)) {
            throw new ManifestInvalid(ManifestInvalid::SRC_MISSING);
        }
        if (!Fs::isDir($src) || !Fs::isInside($src, $pluginDir)) {
            throw new ManifestInvalid(Fs::isDir($src) || is_link($src) ? ManifestInvalid::SRC_OUTSIDE : ManifestInvalid::SRC_MISSING);
        }
        $short = Manifest::shortClass($manifest->class, $manifest->kind, $manifest->id);
        $file = $src . '/' . $short . '.php';
        if ($short === null || !Fs::isFile($file) || !Fs::isInside($file, $src)) {
            throw new ManifestInvalid(ManifestInvalid::CLASS_FILE_MISSING);
        }
    }
}
