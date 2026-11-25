<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Io\Csv\Charset;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;

/**
 * The About facts (M7 spec section 10, ADR 0022 decision 6, P228): read
 * only, outside any transaction. Components and their licence files come
 * from `config/notices.json` of the application root; plugins from the
 * plugin registry. A malformed notices file, or a licence path outside the
 * three allowed roots, is a broken release: \RuntimeException.
 */
final class AboutOperations
{
    public const NOTICES = 'config/notices.json';
    public const MAX_NOTICES_BYTES = 65536;
    public const MAX_LICENCE_BYTES = 262144;
    public const ID_PATTERN = '/^[a-z0-9-]{1,40}\z/';
    public const LICENCE_PATH = '#^(LICENSE|vendor/[A-Za-z0-9._/-]+|public/assets/fonts/[a-z0-9-]+/[A-Za-z0-9._-]+)\z#';

    private const KEYS = ['files', 'id', 'licence', 'name', 'version'];
    private const SPDX = '/^\(?[A-Za-z0-9.+-]+\)?(?: (?:AND|OR|WITH) \(?[A-Za-z0-9.+-]+\)?)*\z/';
    private const MAX_TEXT = 200;

    private readonly string $root;

    /** @param string|null $root the application root; null for this installation's own (tests pass a temporary one) */
    public function __construct(private readonly Context $c, ?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 3), '/');
    }

    public function about(Actor $actor): AboutFacts
    {
        $this->c->requireCurrentActor($actor);
        $admin = $actor->role === Role::Admin;
        $components = [];
        foreach ($this->notices() as $notice) {
            $components[] = new AboutComponent($notice['id'], $notice['name'], $notice['version'], $notice['licence']);
        }
        $plugins = [];
        foreach ($this->c->plugins() as $info) {
            if ($info->manifest === null || $info->status === PluginStatus::Broken) {
                continue;
            }
            $enabled = $info->status === PluginStatus::Enabled;
            if (!$admin && !$enabled) {
                continue;
            }
            $m = $info->manifest;
            $plugins[] = new AboutPlugin($m->name, $m->version, $m->kind, $m->licence, $enabled);
        }
        $server = null;
        if ($admin) {
            $server = ['php' => PHP_VERSION, 'sqlite' => (string) $this->c->db()->value('SELECT sqlite_version()')];
        }

        return new AboutFacts($this->c->appVersion, $components, $plugins, $server);
    }

    public function licence(Actor $actor, string $id): LicenceText
    {
        $this->c->requireCurrentActor($actor);
        foreach ($this->notices() as $notice) {
            if ($notice['id'] !== $id) {
                continue;
            }
            $files = [];
            foreach ($notice['files'] as $file) {
                [$text, $cut] = $this->readLicence($file);
                $files[] = ['file' => $file, 'text' => $text, 'cut' => $cut];
            }

            return new LicenceText($notice['name'], $files);
        }

        throw new NotFound('No such component.');
    }

    /**
     * The elements of the notices file, validated against M7 spec section 3.6.
     *
     * @return list<array{id: string, name: string, version: string, licence: string, files: list<string>}>
     */
    private function notices(): array
    {
        $path = $this->root . '/' . self::NOTICES;
        if (!Fs::isFile($path) || Fs::size($path) > self::MAX_NOTICES_BYTES) {
            throw new \RuntimeException('The notices file is missing or too large.');
        }
        $json = ErrorTrap::run(static fn (): string|false => file_get_contents($path, false, null, 0, self::MAX_NOTICES_BYTES + 1), 'Cannot read the notices file.');
        if (!is_string($json) || strlen($json) > self::MAX_NOTICES_BYTES) {
            throw new \RuntimeException('Cannot read the notices file.');
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('The notices file is not valid JSON.');
        }
        if (!is_array($data) || !array_is_list($data) || $data === []) {
            throw new \RuntimeException('The notices file is not a list of components.');
        }
        $notices = [];
        $seen = [];
        foreach ($data as $element) {
            $notice = self::notice($element);
            if (isset($seen[$notice['id']])) {
                throw new \RuntimeException('The notices file names a component twice.');
            }
            $seen[$notice['id']] = true;
            $notices[] = $notice;
        }

        return $notices;
    }

    /** @return array{id: string, name: string, version: string, licence: string, files: list<string>} */
    private static function notice(mixed $element): array
    {
        if (!is_array($element) || array_is_list($element)) {
            throw new \RuntimeException('A notices element is not an object.');
        }
        $keys = array_keys($element);
        sort($keys);
        if ($keys !== self::KEYS) {
            throw new \RuntimeException('A notices element does not have the keys of the format.');
        }
        ['id' => $id, 'name' => $name, 'version' => $version, 'licence' => $licence, 'files' => $files] = $element;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \RuntimeException('A notices element has a bad id.');
        }
        if (!self::isText($name) || $name === '' || !self::isText($version) || !is_string($licence) || preg_match(self::SPDX, $licence) !== 1) {
            throw new \RuntimeException('A notices element has a bad name, version, or licence.');
        }
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw new \RuntimeException('A notices element has no files.');
        }
        foreach ($files as $file) {
            if (!is_string($file)) {
                throw new \RuntimeException('A notices element has a bad file.');
            }
        }

        /** @var list<string> $files */
        return ['id' => $id, 'name' => $name, 'version' => $version, 'licence' => $licence, 'files' => $files];
    }

    private static function isText(mixed $value): bool
    {
        return is_string($value) && Text::isUtf8($value) && strlen($value) <= self::MAX_TEXT && !Text::hasControl($value);
    }

    /**
     * One licence file's text, at most MAX_LICENCE_BYTES (cut: true when
     * there was more), invalid UTF-8 replaced with U+FFFD. The path must
     * match LICENCE_PATH, hold no empty, `.`, or `..` segment, and name a
     * regular file reached through no symbolic link at all (its real path
     * is the root's real path plus the path as written).
     *
     * @return array{0: string, 1: bool}
     */
    private function readLicence(string $file): array
    {
        if (preg_match(self::LICENCE_PATH, $file) !== 1) {
            throw new \RuntimeException('A licence path is outside the allowed folders.');
        }
        foreach (explode('/', $file) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('A licence path has an empty, current, or parent segment.');
            }
        }
        $path = $this->root . '/' . $file;
        $realRoot = realpath($this->root);
        if (!Fs::isFile($path) || $realRoot === false || realpath($path) !== $realRoot . '/' . $file) {
            throw new \RuntimeException('A licence path is not a regular file reached without links.');
        }
        $text = ErrorTrap::run(static fn (): string|false => file_get_contents($path, false, null, 0, self::MAX_LICENCE_BYTES + 1), 'Cannot read a licence file.');
        if (!is_string($text)) {
            throw new \RuntimeException('Cannot read a licence file.');
        }
        $cut = strlen($text) > self::MAX_LICENCE_BYTES;
        if ($cut) {
            $text = substr($text, 0, self::MAX_LICENCE_BYTES);
        }

        return [Charset::scrub($text), $cut];
    }
}
