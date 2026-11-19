<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Xlsx;

use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;

/**
 * What one call learnt about a workbook, kept for the next calls of the
 * same import job in the job's temporary folder: that the whole-file
 * checks (WorkbookCheck::contents) passed, each sheet's columns, the cell
 * format kinds, and the shared strings. Each later call then does only
 * the zip directory check, the workbook structure, and its own rows.
 *
 * The cache is read back as untrusted data: it is used only when its key
 * (the SHA-256 of the whole uploaded file and its size) matches the file
 * now; the JSON file is at most MAX_CACHE_BYTES, never a link, decoded with
 * a depth limit, and every value is checked for type, count, and length
 * (columns as SourceTable allows, format kinds 0 to 3, the shared strings
 * files by their recorded sizes and SHA-256). Anything else is ignored and
 * recomputed. Writes go to a temporary name and are renamed into place; a
 * folder that cannot be written only means nothing is cached.
 *
 * @internal
 */
final class JobCache
{
    private const FILE = 'xlsx-cache.json';
    private const TEXT = 'xlsx-strings.bin';
    private const OFFSETS = 'xlsx-offsets.bin';
    private const VERSION = 1;

    /**
     * @param array{checked: bool, columns: array<string, list<string>>, styles: ?string, strings: ?array{count: int, bytes: int, text: string, offsets: string}} $data
     */
    private function __construct(private readonly string $folder, private readonly string $key, private array $data)
    {
    }

    public static function for(SourceFile $file): self
    {
        $hash = hash_file('sha256', $file->path);
        $key = ($hash === false ? 'none' : $hash) . ':' . (int) filesize($file->path);
        $folder = rtrim($file->tempFolder, '/');
        $empty = ['checked' => false, 'columns' => [], 'styles' => null, 'strings' => null];
        $raw = self::readFile($folder . '/' . self::FILE, Limits::MAX_CACHE_BYTES);
        if ($raw === null || $hash === false) {
            return new self($folder, $key, $empty);
        }
        try {
            $decoded = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self($folder, $key, $empty);
        }
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== self::VERSION || ($decoded['key'] ?? null) !== $key) {
            return new self($folder, $key, $empty);
        }

        return new self($folder, $key, [
            'checked' => ($decoded['checked'] ?? null) === true,
            'columns' => self::validColumns($decoded['columns'] ?? null),
            'styles' => self::validStyles($decoded['styles'] ?? null),
            'strings' => self::validStrings($decoded['strings'] ?? null),
        ]);
    }

    public function checked(): bool
    {
        return $this->data['checked'];
    }

    public function markChecked(): void
    {
        $this->data['checked'] = true;
        $this->save();
    }

    /** @return list<string>|null */
    public function columns(int $sheet, bool $heading): ?array
    {
        return $this->data['columns'][$sheet . ':' . ($heading ? '1' : '0')] ?? null;
    }

    /** @param list<string> $columns */
    public function setColumns(int $sheet, bool $heading, array $columns): void
    {
        $this->data['columns'][$sheet . ':' . ($heading ? '1' : '0')] = $columns;
        $this->save();
    }

    /** The cell format kinds, one byte each (Styles), or null. */
    public function styles(): ?string
    {
        return $this->data['styles'];
    }

    public function setStyles(string $kinds): void
    {
        $this->data['styles'] = $kinds;
        $this->save();
    }

    public function strings(): ?SharedStrings
    {
        $meta = $this->data['strings'];
        if ($meta === null) {
            return null;
        }
        $text = self::readFile($this->folder . '/' . self::TEXT, $meta['bytes']);
        $offsets = self::readFile($this->folder . '/' . self::OFFSETS, 4 * ($meta['count'] + 1));
        if ($text === null || $offsets === null || strlen($text) !== $meta['bytes'] || strlen($offsets) !== 4 * ($meta['count'] + 1)
            || !hash_equals($meta['text'], hash('sha256', $text)) || !hash_equals($meta['offsets'], hash('sha256', $offsets))) {
            return null;
        }

        return SharedStrings::fromParts($text, $offsets, $meta['count']);
    }

    public function setStrings(SharedStrings $strings): void
    {
        [$text, $offsets, $count] = $strings->parts();
        if (!self::writeFile($this->folder . '/' . self::TEXT, $text) || !self::writeFile($this->folder . '/' . self::OFFSETS, $offsets)) {
            return;
        }
        $this->data['strings'] = ['count' => $count, 'bytes' => strlen($text), 'text' => hash('sha256', $text), 'offsets' => hash('sha256', $offsets)];
        $this->save();
    }

    private function save(): void
    {
        $data = ['v' => self::VERSION, 'key' => $this->key, 'checked' => $this->data['checked'], 'columns' => $this->data['columns'],
            'styles' => $this->data['styles'] === null ? null : base64_encode($this->data['styles']), 'strings' => $this->data['strings']];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json) && strlen($json) > Limits::MAX_CACHE_BYTES) {
            $data['columns'] = [];
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if (is_string($json) && strlen($json) <= Limits::MAX_CACHE_BYTES) {
            self::writeFile($this->folder . '/' . self::FILE, $json);
        }
    }

    /** @return array<string, list<string>> */
    private static function validColumns(mixed $value): array
    {
        $columns = [];
        if (!is_array($value)) {
            return $columns;
        }
        foreach ($value as $key => $list) {
            if (!is_string($key) || preg_match('/^\d{1,3}:[01]\z/', $key) !== 1 || !is_array($list) || !array_is_list($list)
                || $list === [] || count($list) > SourceTable::MAX_COLUMNS) {
                continue;
            }
            foreach ($list as $heading) {
                if (!is_string($heading) || $heading === '' || strlen($heading) > SourceTable::MAX_HEADING_BYTES || preg_match('//u', $heading) !== 1) {
                    continue 2;
                }
            }
            $columns[$key] = $list;
        }

        return $columns;
    }

    private static function validStyles(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2 * Limits::MAX_FORMATS) {
            return null;
        }
        $kinds = base64_decode($value, true);

        return is_string($kinds) && strlen($kinds) <= Limits::MAX_FORMATS && preg_match('/^[\x00-\x03]*\z/', $kinds) === 1 ? $kinds : null;
    }

    /** @return array{count: int, bytes: int, text: string, offsets: string}|null */
    private static function validStrings(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $count = $value['count'] ?? null;
        $bytes = $value['bytes'] ?? null;
        $text = $value['text'] ?? null;
        $offsets = $value['offsets'] ?? null;
        if (!is_int($count) || $count < 0 || $count > Limits::MAX_SHARED_STRINGS || !is_int($bytes) || $bytes < 0 || $bytes > Limits::MAX_SHARED_BYTES
            || !is_string($text) || preg_match('/^[0-9a-f]{64}\z/', $text) !== 1 || !is_string($offsets) || preg_match('/^[0-9a-f]{64}\z/', $offsets) !== 1) {
            return null;
        }

        return ['count' => $count, 'bytes' => $bytes, 'text' => $text, 'offsets' => $offsets];
    }

    /** The bytes of a regular file of at most $max bytes, or null. */
    private static function readFile(string $path, int $max): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || (int) filesize($path) > $max) {
            return null;
        }
        $bytes = self::quietly(static fn () => file_get_contents($path, false, null, 0, $max + 1));

        return is_string($bytes) && strlen($bytes) <= $max ? $bytes : null;
    }

    private static function writeFile(string $path, string $bytes): bool
    {
        $part = $path . '.part';

        return self::quietly(static fn () => file_put_contents($part, $bytes) === strlen($bytes) && rename($part, $path)) === true;
    }

    /** Runs $call with PHP warnings ignored (a cache that cannot be read or written is not an error). */
    private static function quietly(\Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call->__invoke();
        } finally {
            restore_error_handler();
        }
    }
}
