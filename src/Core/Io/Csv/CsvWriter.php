<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Io\Csv;

use TiffinDB\Core\Io\Contract\PluginFailure;

/**
 * Writes CSV as TiffinDB exports it (M3 spec section 8.2, P121): UTF-8
 * with a byte order mark, comma delimiter, records ended by CRLF. A field
 * is quoted when it contains a comma, a quote, CR, or LF, or starts or ends
 * with a space or tab; quotes are doubled. Values are written exactly as
 * given (P135); spreadsheetSafe() is the opt-in protection of P122.
 *
 * Output is written as it is made, so memory does not grow with the
 * number or length of records.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /** Types whose cells spreadsheetSafe() may change (text-like; numbers and dates never). */
    public const TEXT_LIKE = ['text', 'long_text', 'url', 'email', 'phone', 'choice', 'relation', 'attachment'];

    /** @var resource|null */
    private $handle;

    public function __construct(string $path)
    {
        try {
            $handle = CsvReader::quietly(static fn () => fopen($path, 'wb'), 'The export file could not be opened.');
        } catch (\RuntimeException $error) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be opened.', $error);
        }
        if (!is_resource($handle)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be opened.');
        }
        $this->handle = $handle;
        $this->put(self::BOM);
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * Writes one record. Each piece goes straight to the file, so neither
     * a long row nor many rows are ever joined into one string.
     *
     * @param list<string> $cells
     */
    public function row(array $cells): void
    {
        foreach ($cells as $index => $cell) {
            if ($index > 0) {
                $this->put(',');
            }
            $this->put(self::field($cell));
        }
        $this->put("\r\n");
    }

    /** Closes the file. */
    public function close(): void
    {
        if (is_resource($this->handle) && !fclose($this->handle)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be closed.');
        }
        $this->handle = null;
    }

    /** One field as written: quoted when needed, quotes doubled. */
    public static function field(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $last = $text[strlen($text) - 1];
        $needs = strpbrk($text, ",\"\r\n") !== false || $text[0] === ' ' || $text[0] === "\t" || $last === ' ' || $last === "\t";

        return $needs ? '"' . preg_replace('/"/', '""', $text) . '"' : $text;
    }

    /**
     * "Protect against spreadsheet formulas" (P122): text starting with
     * `=`, `+`, `-`, `@`, tab, or CR gets a leading apostrophe.
     */
    public static function spreadsheetSafe(string $text): string
    {
        return $text !== '' && strpbrk($text[0], "=+-@\t\r") !== false ? "'" . $text : $text;
    }

    /**
     * The download name for a table: letters, digits, spaces, `-`, and `_`
     * kept, spaces to `-`, ASCII lower case, at most 60 characters, plus
     * `.csv` (`export.csv` when nothing is left).
     */
    public static function fileName(string $tableName): string
    {
        $name = preg_replace('/[^\p{L}\p{N} _-]/u', '', Charset::scrub($tableName)) ?? '';
        $name = strtolower((string) preg_replace('/ /', '-', $name));
        if (preg_match('/^.{0,60}/su', $name, $match) === 1) {
            $name = $match[0];
        }

        return $name === '' ? 'export.csv' : $name . '.csv';
    }

    private function put(string $bytes): void
    {
        if (!is_resource($this->handle)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file is closed.');
        }
        if ($bytes !== '' && fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The export file could not be written.');
        }
    }
}
