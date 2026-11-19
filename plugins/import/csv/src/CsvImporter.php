<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Plugin\Import\Csv;

use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\Inspection;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\SettingChoice;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Contract\SourceTable;
use TiffinDB\Core\Io\Csv\CsvDetector;
use TiffinDB\Core\Io\Csv\CsvDialect;
use TiffinDB\Core\Io\Csv\CsvReader;

/**
 * The shipped CSV import plugin (M3 spec section 8.1, P121). The reading
 * itself is the core's CSV reader (ADR 0015 decision 2, P143); this class
 * turns its dialect into the reading options and back. Labels are plain
 * English text (P133).
 */
final class CsvImporter implements Importer
{
    public function detect(SourceFile $file): Settings
    {
        return self::settingsFor(CsvDetector::detect($file->path));
    }

    public function inspect(SourceFile $file, Settings $settings): Inspection
    {
        $dialect = CsvDialect::fromValues($settings->values());
        $columns = CsvReader::columnsOf($file->path, $dialect, $file->tempFolder);

        return new Inspection([new SourceTable('', $columns, null)], $dialect->readAs());
    }

    public function rows(SourceFile $file, Settings $settings, int $table, int $from, int $limit): iterable
    {
        if ($table !== 0) {
            return [];
        }

        return CsvReader::rowsOf($file->path, CsvDialect::fromValues($settings->values()), $file->tempFolder, $from, $limit);
    }

    /** The reading options, holding the values of $dialect. */
    public static function settingsFor(CsvDialect $dialect): Settings
    {
        $values = $dialect->values();
        $encodings = [];
        foreach (CsvDialect::CHARSETS as $value => $label) {
            $encodings[] = new SettingChoice($value, $label);
        }
        $delimiters = [];
        foreach (CsvDialect::DELIMITERS as $value => [, $label]) {
            $delimiters[] = new SettingChoice($value, $label);
        }

        return new Settings([
            new Setting('encoding', 'Text encoding', 'choice', $values['encoding'], $encodings, 'How the characters in the file are stored. Most files are UTF-8.'),
            new Setting('delimiter', 'Values are separated by', 'choice', $values['delimiter'], $delimiters),
            new Setting('heading', 'The first row holds the column names', 'boolean', $values['heading']),
        ]);
    }
}
