<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Csv;

use TiffinDB\Core\Io\Contract\Exporter;
use TiffinDB\Core\Io\Contract\Importer;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\RepoFiles;

/** The shipped CSV plugins, found and loaded the way the application does (scanner, then loader). */
final class CsvPlugins
{
    public static function importer(): Importer
    {
        return self::loader()->importer(self::find('import'));
    }

    public static function exporter(): Exporter
    {
        return self::loader()->exporter(self::find('export'));
    }

    private static function loader(): PluginLoader
    {
        return new PluginLoader(RepoFiles::path('plugins'));
    }

    private static function find(string $kind): PluginInfo
    {
        foreach ((new PluginScanner(RepoFiles::path('plugins')))->scan([]) as $info) {
            if ($info->kind === $kind && $info->id === 'csv') {
                if ($info->status !== PluginStatus::Enabled) {
                    throw new \RuntimeException('The CSV ' . $kind . ' plugin is not enabled: ' . $info->status->value);
                }

                return $info;
            }
        }

        throw new \RuntimeException('The CSV ' . $kind . ' plugin was not found.');
    }
}
