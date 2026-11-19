<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Plugins\Xlsx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-XLSX-5: both XLSX plugins report themselves unavailable when one of
 * the PHP extensions their manifests require is missing (the scanner's
 * extension seam), while the CSV and JSON plugins stay enabled.
 */
#[CoversNothing]
final class XlsxAvailabilityTest extends TestCase
{
    /** @return array<string, PluginStatus> '<kind>/<id>' => status */
    private static function statuses(?string $missing): array
    {
        $scanner = new PluginScanner(RepoFiles::path('plugins'), static fn (string $name): bool => $name !== $missing && extension_loaded($name));
        $statuses = [];
        foreach ($scanner->scan([]) as $info) {
            $statuses[$info->ref()] = $info->status;
        }

        return $statuses;
    }

    public function testEnabledWithEveryExtension(): void
    {
        $statuses = self::statuses(null);
        self::assertSame(PluginStatus::Enabled, $statuses['import/xlsx']);
        self::assertSame(PluginStatus::Enabled, $statuses['export/xlsx']);
    }

    public function testUnavailableWithoutAnyRequiredExtension(): void
    {
        foreach (['dom', 'zip', 'xmlreader', 'filter', 'libxml'] as $extension) {
            $statuses = self::statuses($extension);
            self::assertSame(PluginStatus::Unavailable, $statuses['import/xlsx'], $extension);
            self::assertSame(PluginStatus::Unavailable, $statuses['export/xlsx'], $extension);
            self::assertSame(PluginStatus::Enabled, $statuses['import/csv'], $extension);
            self::assertSame(PluginStatus::Enabled, $statuses['export/csv'], $extension);
            self::assertSame(PluginStatus::Enabled, $statuses['export/json'], $extension);
        }
    }

    public function testMissingExtensionsAreNamed(): void
    {
        $scanner = new PluginScanner(RepoFiles::path('plugins'), static fn (string $name): bool => $name !== 'dom');
        foreach ($scanner->scan([]) as $info) {
            if ($info->id === 'xlsx') {
                self::assertSame(['dom'], $info->missingExtensions, $info->ref());
            }
        }
    }
}
