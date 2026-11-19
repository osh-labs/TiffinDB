<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Export\Csv;

use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\ExportSource;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Csv\CsvWriter;

/**
 * The shipped CSV export plugin (M3 spec section 8.2, P121 to P123, P135):
 * one table per file, a heading row of field names, then one row per live
 * record in id order, each cell the value's text (checkboxes as yes or
 * no, P145). Values are exact unless
 * "Protect against spreadsheet formulas" is chosen (off by default).
 */
final class CsvExporter implements Exporter
{
    public function settings(string $scope): Settings
    {
        if ($scope !== 'table') {
            return new Settings([]);
        }

        return new Settings([
            new Setting('record_ids', "Add a column with each record's number", 'boolean', '0'),
            new Setting(
                'spreadsheet_safe',
                'Protect against spreadsheet formulas',
                'boolean',
                '0',
                [],
                'Puts an apostrophe before text that starts with =, +, -, @, a tab, or a carriage return, so spreadsheet programs do not treat it as a formula. The apostrophe becomes part of the value.',
            ),
        ]);
    }

    public function write(ExportSource $source, Settings $settings, string $targetPath): void
    {
        $tables = $source->tables();
        if (count($tables) !== 1) {
            throw new PluginFailure(PluginFailure::REASON_UNSUPPORTED, 'A CSV file holds one table.');
        }
        $table = $tables[0];
        $ids = $settings->value('record_ids') === '1';
        $safe = $settings->value('spreadsheet_safe') === '1';

        $writer = new CsvWriter($targetPath);
        $heading = $ids ? ['id'] : [];
        $protect = [];
        foreach ($table->fields as $field) {
            $heading[] = $safe ? CsvWriter::spreadsheetSafe($field->name) : $field->name;
            $protect[$field->key] = $safe && in_array($field->type, CsvWriter::TEXT_LIKE, true);
        }
        $writer->row($heading);
        foreach ($source->records($table->key) as $record) {
            $cells = $ids ? [(string) $record->id] : [];
            foreach ($table->fields as $field) {
                $text = isset($record->values[$field->key]) ? $record->values[$field->key]->text : '';
                if ($field->type === 'checkbox') {
                    $text = self::checkboxText($text);
                }
                $cells[] = $protect[$field->key] ? CsvWriter::spreadsheetSafe($text) : $text;
            }
            $writer->row($cells);
        }
        $writer->close();
    }

    /**
     * A checkbox cell as CSV writes it (M3 spec section 8.2, P145): the
     * core's text is "1" or "0" (section 3.3); the file says "yes" or "no",
     * which the CSV import reads back as a checkbox. Applied by field type
     * only, so a text field holding "1" is written as it is.
     */
    public static function checkboxText(string $text): string
    {
        return match ($text) {
            '1' => 'yes',
            '0' => 'no',
            default => $text,
        };
    }
}
