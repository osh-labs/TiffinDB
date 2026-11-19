<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Export\Xlsx;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\ExportField;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\ExportValue;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\Settings;

/**
 * The shipped XLSX export plugin (M3 spec section 9.3, ADR 0016 decision
 * 4): one sheet per table in scope, a bold heading row of field names,
 * then the live records in id order. Written with OpenSpout's streaming
 * XLSX writer, whose temporary folder is the job folder's `tmp/`.
 *
 * Every cell is built explicitly; `Cell::fromValue` and `Row::fromValues`
 * are never called (they turn text starting with `=` into a formula), so
 * no text is ever a formula and the file is safe to open in a spreadsheet
 * program. Text is written inline (no shared strings part).
 *
 * Cells per field type: text-like types, relations, and attachments are
 * string cells of the value's text; numbers, ratings, and auto-numbers are
 * numeric; currency is numeric (minor units over 10^digits) with format
 * `#,##0.00` (zeros per `minor_digits`); dates and date-times are date
 * cells with `yyyy-mm-dd` and `yyyy-mm-dd hh:mm[:ss]`; times and durations
 * are string cells of their text; checkboxes are boolean cells; empty
 * values are empty cells; a type this plugin does not know is a string
 * cell of its text.
 *
 * Exact values (WP5): the writer prints a float with PHP's `precision`
 * setting (14 significant digits by default). A number or currency amount
 * that would not read back exactly that way is written as a string cell of
 * its exact text instead. Dates before 1900-03-01, which the 1900 date
 * system cannot hold (or holds off by one day), are string cells of their
 * text too. Text longer than a spreadsheet cell's 32,767 characters is cut
 * to that length (TiffinDB's own fields are shorter; joined link titles
 * can be longer).
 */
final class XlsxExporter implements Exporter
{
    private const CELL_CHARS = 32767;
    private const SHEET_NAME_CHARS = 31;
    private const FIRST_DATE = '1900-03-01';
    private const NUMBER_TYPES = ['number', 'rating', 'auto_number'];
    private const STRING_TYPES = ['text', 'long_text', 'url', 'email', 'phone', 'choice', 'relation', 'attachment', 'time', 'duration'];

    public function settings(string $scope): Settings
    {
        return new Settings([
            new Setting('record_ids', "Add a column with each record's number", 'boolean', '0'),
        ]);
    }

    public function write(ExportSource $source, Settings $settings, string $targetPath): void
    {
        $tempFolder = dirname($targetPath) . '/tmp';
        if (!is_dir($tempFolder) || !is_writable($tempFolder)) {
            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The job folder has no temporary folder.');
        }
        $ids = $settings->value('record_ids') === '1';
        $writer = new Writer(new Options(tempFolder: $tempFolder));
        try {
            $writer->openToFile($targetPath);
            $bold = new Style(fontBold: true);
            $names = [];
            foreach ($source->tables() as $index => $table) {
                $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName(self::sheetName($table->name, $names));
                $heading = $ids ? [new StringCell('id', $bold)] : [];
                $styles = [];
                foreach ($table->fields as $field) {
                    $heading[] = new StringCell(self::cut($field->name), $bold);
                    $styles[$field->key] = self::style($field);
                }
                $writer->addRow(new Row($heading));
                foreach ($source->records($table->key) as $record) {
                    $cells = $ids ? [new NumericCell($record->id)] : [];
                    foreach ($table->fields as $field) {
                        $cells[] = self::cell($field, $record->values[$field->key] ?? null, $styles[$field->key]);
                    }
                    $writer->addRow(new Row($cells));
                }
            }
            $writer->close();
        } catch (OpenSpoutException $error) {
            self::abandon($writer, $targetPath);

            throw new PluginFailure(PluginFailure::REASON_FAILED, 'The workbook could not be written.', $error);
        } catch (\Throwable $error) {
            // A failing export source or a PluginFailure: passed on unchanged, the partial file removed.
            self::abandon($writer, $targetPath);

            throw $error;
        }
    }

    /** The cell of one value. */
    private static function cell(ExportField $field, ?ExportValue $value, ?Style $style): Cell
    {
        $raw = $value?->value;
        $text = $value === null ? '' : $value->text;
        if ($raw === null && $text === '' && $field->type !== 'checkbox') {
            return new EmptyCell(null);
        }
        switch ($field->type) {
            case 'checkbox':
                return new BooleanCell($raw === true);
            case 'number':
            case 'rating':
            case 'auto_number':
                if (is_int($raw) || is_float($raw) && is_finite($raw)) {
                    return self::exact($raw) ? new NumericCell($raw) : new StringCell(self::cut($text));
                }
                break;
            case 'currency':
                if (is_int($raw)) {
                    $digits = self::minorDigits($field->options);
                    $amount = $digits === 0 ? $raw : $raw / 10 ** $digits;
                    $decimal = self::decimal($raw, $digits);
                    if (is_int($amount) || (float) $decimal === $amount && self::exact($amount)) {
                        return new NumericCell($amount, $style);
                    }

                    return new StringCell($decimal);
                }
                break;
            case 'date':
            case 'date_time':
                $date = is_string($raw) ? self::date($raw) : null;
                if ($date !== null && substr($raw, 0, 10) >= self::FIRST_DATE) {
                    return new DateTimeCell($date, $style);
                }
                break;
        }

        return $text === '' ? new EmptyCell(null) : new StringCell(self::cut($text));
    }

    /** The number format style of a field's cells, or null. */
    private static function style(ExportField $field): ?Style
    {
        if ($field->type === 'currency') {
            $digits = self::minorDigits($field->options);

            return new Style(format: $digits === 0 ? '#,##0' : '#,##0.' . str_repeat('0', $digits));
        }
        if ($field->type === 'date') {
            return new Style(format: 'yyyy-mm-dd');
        }
        if ($field->type === 'date_time') {
            return new Style(format: ($field->options['seconds'] ?? false) === true ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd hh:mm');
        }

        return null;
    }

    /** Whether the writer's text of $value (PHP's float-to-string conversion) reads back as $value. */
    private static function exact(int|float $value): bool
    {
        return is_int($value) || (float) (string) $value === $value;
    }

    /** A canonical date (`YYYY-MM-DD`) or date and time (`YYYY-MM-DDTHH:MM:SS`), or null. */
    private static function date(string $value): ?\DateTimeImmutable
    {
        $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\TH:i:s';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format(strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i:s') === $value ? $date : null;
    }

    /** The decimal text of $minor minor units with $digits fraction digits ("-5.50"). */
    private static function decimal(int $minor, int $digits): string
    {
        $text = (string) $minor;
        if ($digits === 0) {
            return $text;
        }
        $negative = str_starts_with($text, '-');
        $magnitude = str_pad($negative ? substr($text, 1) : $text, $digits + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . substr($magnitude, 0, -$digits) . '.' . substr($magnitude, -$digits);
    }

    /** @param array<string, mixed> $options */
    private static function minorDigits(array $options): int
    {
        $digits = $options['minor_digits'] ?? 2;

        return is_int($digits) && $digits >= 0 && $digits <= 9 ? $digits : 2;
    }

    /**
     * A sheet name from a table name (spec 9.3): `[ ] : * ? / \` removed,
     * apostrophes at either end removed, cut to 31 characters, "Sheet" when
     * empty, made unique ignoring ASCII case with " 2", " 3".
     *
     * @param array<string, true> $taken lower-case names already used (updated)
     */
    private static function sheetName(string $name, array &$taken): string
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $name));
        $name = trim($name, "'");
        $name = $name === '' ? 'Sheet' : $name;
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '' : ' ' . $n;
            $candidate = rtrim(self::chars($name, self::SHEET_NAME_CHARS - strlen($suffix)), " '") . $suffix;
            if ($candidate === $suffix) {
                $candidate = 'Sheet' . $suffix;
            }
            if (!isset($taken[strtolower($candidate)])) {
                $taken[strtolower($candidate)] = true;

                return $candidate;
            }
        }
    }

    /** Text cut to the 32,767 characters a spreadsheet cell holds. */
    private static function cut(string $text): string
    {
        return strlen($text) <= self::CELL_CHARS ? $text : self::chars($text, self::CELL_CHARS);
    }

    /**
     * $text cut to $max characters. Without mbstring the writer counts
     * bytes, so the cut is then to $max bytes at a character boundary.
     */
    private static function chars(string $text, int $max): string
    {
        if (preg_match('/^.{0,' . $max . '}/su', $text, $m) === 1) {
            $text = $m[0];
        }
        if (strlen($text) > $max && !function_exists('mb_strlen')) {
            preg_match('/^.{0,' . $max . '}/s', $text, $m);
            $text = (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*\z/', '', $m[0]);
            if (preg_match('//u', $text) !== 1) {
                $text = '';
            }
        }

        return $text;
    }

    private static function abandon(Writer $writer, string $targetPath): void
    {
        try {
            $writer->close();
        } catch (\Throwable) {
            // The file is removed below.
        }
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
    }
}
