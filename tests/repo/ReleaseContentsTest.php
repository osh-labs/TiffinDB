<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tools\Release\ReleaseRules;

require_once __DIR__ . '/../../tools/release/ReleaseRules.php';

/**
 * T-REL-11 (M7 release spec section 16.3): the files the current tree would
 * ship pass the release rules R1 to R5, R7, and R8. R6 needs a prepared
 * production vendor and is checked by ReleaseBuildTest and the CI build
 * step. The vendor files added here come from the installed vendor/ through
 * the same filter the build uses, so R7 can see the shipped licence files.
 *
 * Also checks the notices (section 3.6) and the changelog (section 3.7).
 */
#[CoversNothing]
final class ReleaseContentsTest extends TestCase
{
    /** @return list<string> */
    private static function archivePaths(): array
    {
        $record = RepoFiles::read(ReleaseRules::DEPENDENCIES);

        return [
            ...ReleaseRules::select(RepoFiles::all()),
            ...ReleaseRules::vendorFiles(RepoFiles::path('vendor'), $record),
        ];
    }

    public function testTheCurrentTreePassesTheReleaseRules(): void
    {
        $paths = self::archivePaths();
        self::assertContains('src/Web/Kernel.php', $paths);
        self::assertContains('vendor/openspout/openspout/LICENSE', $paths);

        self::assertSame(
            [],
            ReleaseRules::violations($paths, RepoFiles::root(), RepoFiles::path('vendor'), ['R1', 'R2', 'R3', 'R4', 'R5', 'R7', 'R8']),
        );
    }

    public function testTheIncludeListNamesNoDevelopmentPathAndNoPluginOrTool(): void
    {
        foreach (ReleaseRules::INCLUDE as $entry) {
            foreach (ReleaseRules::DEVELOPMENT_PREFIXES as $prefix) {
                self::assertFalse(str_starts_with($entry, $prefix) || str_starts_with($prefix, $entry), "{$entry} overlaps {$prefix}");
            }
            self::assertNotContains($entry, ReleaseRules::DEVELOPMENT_FILES);
            self::assertDoesNotMatchRegularExpression('#^(plugins|tools/admin)/.+#', $entry, 'The include list names no plugin and no admin tool.');
        }
    }

    public function testTheIncludeListSelectsNoTestMaterial(): void
    {
        foreach (ReleaseRules::select(RepoFiles::all()) as $path) {
            self::assertStringStartsNotWith('tests/', $path);
        }
        self::assertNotContains('tests/Support/M5Fixture.php', ReleaseRules::select(RepoFiles::all()));
    }

    public function testTheNoticesAreWellFormedAndShippedOnly(): void
    {
        $components = ReleaseRules::components(RepoFiles::read(ReleaseRules::NOTICES));
        self::assertIsArray($components);

        $ids = array_column($components, 'id');
        self::assertSame(
            ['tiffindb', 'openspout', 'composer-autoloader', 'inter', 'atkinson-hyperlegible', 'lexend', 'source-serif-4'],
            $ids,
        );

        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        foreach ($components as $component) {
            // The paths the About page may read (section 10.1).
            foreach ($component['files'] as $file) {
                self::assertMatchesRegularExpression('#^(LICENSE|vendor/[A-Za-z0-9._/-]+|public/assets/fonts/[a-z0-9-]+/[A-Za-z0-9._-]+)\z#', $file);
                self::assertStringNotContainsString('..', $file);
            }
            self::assertStringNotContainsString('plugin', strtolower($component['name'] . $component['id']));
        }
        $byId = array_combine($ids, $components);
        self::assertSame(['LICENSE'], $byId['tiffindb']['files']);
        self::assertSame('AGPL-3.0-or-later', $byId['tiffindb']['licence']);
        self::assertContains($byId['tiffindb']['version'], ['', $config['version']], 'The TiffinDB component gives the configured version or none.');
        self::assertSame('MIT AND Apache-2.0', $byId['openspout']['licence']);
        self::assertSame('MIT', $byId['composer-autoloader']['licence']);
        self::assertSame(['vendor/composer/LICENSE'], $byId['composer-autoloader']['files']);
        foreach (['inter', 'atkinson-hyperlegible', 'lexend', 'source-serif-4'] as $font) {
            self::assertSame('OFL-1.1', $byId[$font]['licence']);
            self::assertSame(["public/assets/fonts/{$font}/LICENSE.txt"], $byId[$font]['files']);
        }
    }

    public function testTheShippedRowsOfTheRecordAreReadAndTheLoaderRowIsMit(): void
    {
        $record = RepoFiles::read(ReleaseRules::DEPENDENCIES);
        $rows = array_column(ReleaseRules::shippedRows($record), 'licenceFiles', 'row');

        self::assertSame([
            'openspout/openspout' => ['vendor/openspout/openspout/LICENSE', 'vendor/openspout/openspout/LICENSE-for-cc42c1d'],
            'Composer class loader' => ['vendor/composer/LICENSE'],
            'Inter' => ['public/assets/fonts/inter/LICENSE.txt'],
            'Atkinson Hyperlegible' => ['public/assets/fonts/atkinson-hyperlegible/LICENSE.txt'],
            'Lexend' => ['public/assets/fonts/lexend/LICENSE.txt'],
            'Source Serif 4' => ['public/assets/fonts/source-serif-4/LICENSE.txt'],
        ], $rows);

        foreach (ReleaseRules::tables($record) as $table) {
            if ($table['section'] === ReleaseRules::LOADER_SECTION) {
                $column = array_search('Licence (SPDX)', $table['header'], true);
                self::assertNotFalse($column);
                self::assertSame(['MIT'], array_map(static fn (array $row): string => $row[$column] ?? '', $table['rows']));

                return;
            }
        }
        self::fail('The record has no class loader table.');
    }

    public function testThirdPartyNoticesNameEveryComponent(): void
    {
        $text = RepoFiles::read('THIRD-PARTY-NOTICES.md');
        foreach (ReleaseRules::components(RepoFiles::read(ReleaseRules::NOTICES)) ?? [] as $component) {
            if ($component['id'] === 'tiffindb') {
                continue;
            }
            self::assertStringContainsString('(`' . $component['id'] . '`)', $text);
            self::assertStringContainsString($component['licence'], $text);
            foreach ($component['files'] as $file) {
                self::assertStringContainsString('`' . $file . '`', $text);
            }
        }
        self::assertStringNotContainsStringIgnoringCase('plugins/', $text);
    }

    public function testTheChangelogStartsAtTheFirstRelease(): void
    {
        $text = RepoFiles::read('CHANGELOG.md');

        self::assertSame(1, preg_match_all('/^## /m', $text));
        self::assertStringContainsString("\n## 1.0.0 (not yet released)\n", $text);
        self::assertDoesNotMatchRegularExpression('#https?://#', $text, 'Only repository-relative links.');
    }
}
