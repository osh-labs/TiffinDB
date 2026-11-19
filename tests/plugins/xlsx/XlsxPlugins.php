<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Contract\PluginFailure;
use TiffinDB\Core\Io\Contract\Setting;
use TiffinDB\Core\Io\Contract\SettingChoice;
use TiffinDB\Core\Io\Contract\Settings;
use TiffinDB\Core\Io\Contract\SourceFile;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * The shipped XLSX plugins, found and loaded the way the application does
 * (scanner, then loader), and small helpers to read a whole sheet through
 * the importer contract.
 */
final class XlsxPlugins
{
    public static function importer(): Importer
    {
        return self::loader()->importer(self::find('import'));
    }

    public static function exporter(): Exporter
    {
        return self::loader()->exporter(self::find('export'));
    }

    /**
     * Every data row of table $table, read in calls of $chunk rows as the
     * core does (each call starting where the last one ended).
     *
     * @return list<list<?string>>
     */
    public static function allRows(Importer $importer, SourceFile $file, Settings $settings, int $table = 0, int $chunk = 2000): array
    {
        $rows = [];
        while (true) {
            $got = 0;
            foreach ($importer->rows($file, $settings, $table, count($rows), $chunk) as $row) {
                $rows[] = $row;
                $got++;
            }
            if ($got < $chunk) {
                return $rows;
            }
        }
    }

    /** The importer's settings for a one-sheet workbook with a heading row (for calls when detect() failed). */
    public static function defaultSettings(): Settings
    {
        return new Settings([
            new Setting('sheet', 'Sheet', 'choice', '0', [new SettingChoice('0', 'Sheet 1')]),
            new Setting('heading', 'The first row holds the column names', 'boolean', '1'),
        ]);
    }

    /** The PluginFailure reason of $call, or null when it does not fail. */
    public static function failure(\Closure $call): ?string
    {
        try {
            $result = $call();
            if (is_iterable($result)) {
                foreach ($result as $row) {
                    // Run a generator to its end.
                }
            }
        } catch (PluginFailure $failure) {
            return $failure->reason;
        }

        return null;
    }

    private static function loader(): PluginLoader
    {
        return new PluginLoader(RepoFiles::path('plugins'));
    }

    private static function find(string $kind): PluginInfo
    {
        foreach ((new PluginScanner(RepoFiles::path('plugins')))->scan([]) as $info) {
            if ($info->kind === $kind && $info->id === 'xlsx') {
                if ($info->status !== PluginStatus::Enabled) {
                    throw new \RuntimeException('The XLSX ' . $kind . ' plugin is not enabled: ' . $info->status->value);
                }

                return $info;
            }
        }

        throw new \RuntimeException('The XLSX ' . $kind . ' plugin was not found.');
    }
}
