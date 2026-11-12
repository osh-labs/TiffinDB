<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-REPO-6: every installed Composer package declares at least one licence
 * from the CLAUDE.md allowlist, expressed as SPDX identifiers; a package that
 * declares none fails.
 *
 * Also checks the dependency record (M0 spec section 11): once
 * `docs/dependencies.md` exists, its tables are parsed; every licence cell
 * must be allowlisted (OFL-1.1 only in the bundled font assets table), and
 * every direct dependency in `composer.json` must have a row in the category
 * matching its section (`require` runtime, `require-dev` development) with
 * its exact constraint in the Version cell. While the file
 * does not exist yet, that check passes trivially and its data set name says
 * so; nothing is skipped.
 */
#[CoversNothing]
final class DependencyLicenceTest extends TestCase
{
    /** CLAUDE.md allowlist as SPDX identifiers (M0 spec T-REPO-6). */
    public const ALLOWED_LICENCES = [
        'MIT',
        'BSD-2-Clause',
        'BSD-3-Clause',
        'Apache-2.0',
        'ISC',
        'LGPL-2.1-or-later',
        'LGPL-3.0-or-later',
        'LGPL-3.0-only',
        'MPL-2.0',
        'GPL-3.0-or-later',
        'GPL-3.0-only',
        'AGPL-3.0-or-later',
        'AGPL-3.0-only',
    ];

    private const RECORD = 'docs/dependencies.md';
    private const RUNTIME = 'Runtime dependencies (shipped)';
    private const FONTS = 'Bundled font assets (shipped)';
    private const DEVELOPMENT = 'Development dependencies (not shipped)';
    private const CATEGORIES = [self::RUNTIME, self::FONTS, self::DEVELOPMENT];

    private ?string $temp = null;

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            foreach (glob($this->temp . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->temp);
            $this->temp = null;
        }
    }

    // ---------------------------------------------------------------- rules

    /**
     * Packages from an installed.json document (Composer 2 object form or
     * Composer 1 list form), as name => declared licences.
     *
     * @return array<string, list<string>>
     */
    public static function installedPackages(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('installed.json is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException('installed.json has an unexpected shape.');
        }
        $packages = array_is_list($data) ? $data : ($data['packages'] ?? null);
        if (!is_array($packages)) {
            throw new RuntimeException('installed.json has no package list.');
        }

        $result = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                throw new RuntimeException('installed.json lists a package without a name.');
            }
            $licence = $package['license'] ?? [];
            $licences = is_string($licence) ? [$licence] : (is_array($licence) ? $licence : []);
            $result[$package['name']] = array_values(array_filter($licences, 'is_string'));
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Whether one declared licence entry is acceptable: an allowlisted
     * identifier, or a disjunction ("or") with at least one allowlisted
     * operand. Conjunctions and exceptions ("and", "with") are not accepted.
     */
    public static function isAllowedEntry(string $entry): bool
    {
        $expression = trim(str_replace(['(', ')'], ' ', $entry));
        if ($expression === '' || preg_match('/\s(and|with)\s/i', " {$expression} ") === 1) {
            return false;
        }
        foreach (preg_split('/\s+or\s+/i', $expression) ?: [] as $operand) {
            if (in_array(trim($operand), self::ALLOWED_LICENCES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<string>> $packages
     * @return list<string>
     */
    public static function licenceViolations(array $packages): array
    {
        $found = [];
        foreach ($packages as $name => $licences) {
            if ($licences === []) {
                $found[] = "{$name}: declares no licence";
            } elseif (array_filter($licences, [self::class, 'isAllowedEntry']) === []) {
                $found[] = "{$name}: declares only " . implode(', ', $licences) . ', none of which is allowed';
            }
        }

        return $found;
    }

    /**
     * Direct dependencies from a composer.json document, by section
     * (`require`, `require-dev`), as name => constraint. Platform
     * requirements (php, ext-*, lib-*, composer-*) are not packages.
     *
     * @return array{require: array<string, string>, require-dev: array<string, string>}
     */
    public static function directDependencies(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $result = ['require' => [], 'require-dev' => []];
        foreach (array_keys($result) as $section) {
            foreach (is_array($data[$section] ?? null) ? $data[$section] : [] as $name => $constraint) {
                if ($name === 'php' || preg_match('/^(ext|lib|composer)-/', (string) $name) === 1) {
                    continue;
                }
                $result[$section][(string) $name] = (string) $constraint;
            }
            ksort($result[$section], SORT_STRING);
        }

        return $result;
    }

    /**
     * The Markdown tables of a document with the `## ` section each sits in.
     * Cells are trimmed and stripped of backticks.
     *
     * @return list<array{section: string, header: list<string>, rows: list<list<string>>}>
     */
    public static function tables(string $markdown): array
    {
        $tables = [];
        $section = '';
        $current = null;
        foreach (preg_split('/\r\n|\n|\r/', $markdown) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '|')) {
                $cells = array_map(
                    static fn (string $cell): string => trim(str_replace('`', '', $cell)),
                    explode('|', trim($line, '|')),
                );
                if ($current === null) {
                    $current = ['section' => $section, 'header' => $cells, 'rows' => []];
                } elseif (preg_match('/^\|?(\s*:?-+:?\s*\|)*\s*:?-+:?\s*\|?$/', $line) !== 1) {
                    $current['rows'][] = $cells;
                }
                continue;
            }
            if ($current !== null) {
                $tables[] = $current;
                $current = null;
            }
            if (str_starts_with($line, '## ')) {
                $section = trim(substr($line, 3));
            }
        }
        if ($current !== null) {
            $tables[] = $current;
        }

        return $tables;
    }

    /**
     * Problems with the dependency record (M0 spec section 11): the three
     * category headings in order; every licence cell allowlisted, with
     * OFL-1.1 accepted only for bundled font assets; no licence table outside
     * the categories; every direct dependency recorded, in the category that
     * matches its composer.json section, with its exact constraint.
     *
     * @param array{require: array<string, string>, require-dev: array<string, string>} $dependencies
     * @return list<string>
     */
    public static function recordProblems(array $dependencies, string $record): array
    {
        $problems = [];

        preg_match_all('/^## (.+?)\s*$/m', $record, $matches);
        $positions = [];
        foreach (self::CATEGORIES as $category) {
            $index = array_search($category, $matches[1], true);
            if ($index === false) {
                $problems[] = "missing section '{$category}'";
            } else {
                $positions[] = $index;
            }
        }
        $sorted = $positions;
        sort($sorted);
        if ($positions !== $sorted) {
            $problems[] = 'the category sections must be in the order: ' . implode(', ', self::CATEGORIES);
        }

        /** @var array<string, list<array{string, string}>> $packageRows name => list of [section, version cell] */
        $packageRows = [];
        foreach (self::tables($record) as $table) {
            $licenceColumn = array_search('Licence', $table['header'], true);
            if ($licenceColumn === false) {
                continue;
            }
            $section = $table['section'];
            if (!in_array($section, self::CATEGORIES, true)) {
                $problems[] = "licence table outside the category sections, in '{$section}'";
                continue;
            }
            $isFonts = $section === self::FONTS;
            $versionColumn = array_search('Version', $table['header'], true);
            foreach ($table['rows'] as $row) {
                $name = $row[0] ?? '';
                $licence = $row[$licenceColumn] ?? '';
                $allowed = self::isAllowedEntry($licence) || ($isFonts && $licence === 'OFL-1.1');
                if (!$allowed) {
                    $problems[] = $licence === 'OFL-1.1'
                        ? "{$name}: OFL-1.1 is allowed for bundled font assets only"
                        : "{$name}: licence '{$licence}' is not allowed";
                }
                if (!$isFonts) {
                    $version = $versionColumn === false ? '' : ($row[$versionColumn] ?? '');
                    $packageRows[$name][] = [$section, $version];
                }
            }
        }

        $expectedSection = ['require' => self::RUNTIME, 'require-dev' => self::DEVELOPMENT];
        foreach ($dependencies as $composerSection => $packages) {
            foreach ($packages as $name => $constraint) {
                $want = $expectedSection[$composerSection];
                $rows = $packageRows[$name] ?? [];
                if ($rows === []) {
                    $problems[] = "{$name}: no row (expected in '{$want}')";
                    continue;
                }
                foreach ($rows as [$section, $version]) {
                    if ($section !== $want) {
                        $problems[] = "{$name}: row in '{$section}', but composer.json has it in {$composerSection} (expected '{$want}')";
                    }
                    $pattern = '/(?<![A-Za-z0-9_.-])' . preg_quote($constraint, '/') . '(?![A-Za-z0-9_-]|\.\d)/';
                    if (preg_match($pattern, $version) !== 1) {
                        $problems[] = "{$name}: version cell does not give the composer.json constraint {$constraint}";
                    }
                }
            }
        }

        return $problems;
    }

    // ------------------------------------------------------- the repository

    public function testEveryInstalledPackageDeclaresAnAllowedLicence(): void
    {
        $installed = 'vendor/composer/installed.json';
        self::assertFileExists(RepoFiles::path($installed), 'Run composer install before the tests.');
        $packages = self::installedPackages(RepoFiles::read($installed));

        self::assertArrayHasKey('phpunit/phpunit', $packages, 'The installed package list looks incomplete.');
        self::assertSame([], self::licenceViolations($packages));
    }

    /** @return iterable<string, array{bool}> */
    public static function recordState(): iterable
    {
        if (is_file(RepoFiles::path(self::RECORD))) {
            yield self::RECORD . ' exists: licences, categories, and direct dependencies are checked' => [true];
        } else {
            yield self::RECORD . ' does not exist yet: the record check passes trivially' => [false];
        }
    }

    #[DataProvider('recordState')]
    public function testDirectDependenciesAreRecorded(bool $recordExists): void
    {
        $dependencies = self::directDependencies(RepoFiles::read('composer.json'));
        self::assertArrayHasKey('phpunit/phpunit', $dependencies['require-dev']);

        if (!$recordExists) {
            self::assertFileDoesNotExist(
                RepoFiles::path(self::RECORD),
                self::RECORD . ' does not exist yet, so there is no record to check.',
            );

            return;
        }

        self::assertSame(
            [],
            self::recordProblems($dependencies, RepoFiles::read(self::RECORD)),
            self::RECORD . ' must follow M0 spec section 11.',
        );
    }

    // ------------------------------------------------- fixtures: licences

    /** @return iterable<string, array{string, list<string>}> */
    public static function installedFixtures(): iterable
    {
        $v2 = static fn (array $packages): string => json_encode(['packages' => $packages, 'dev' => true], JSON_THROW_ON_ERROR);

        yield 'all allowed (Composer 2 form)' => [$v2([
            ['name' => 'example/a', 'license' => ['MIT']],
            ['name' => 'example/b', 'license' => ['BSD-3-Clause']],
            ['name' => 'example/c', 'license' => ['LGPL-2.1-or-later', 'GPL-2.0-only']],
            ['name' => 'example/d', 'license' => ['(GPL-2.0-only or MPL-2.0)']],
            ['name' => 'example/e', 'license' => 'Apache-2.0'],
        ]), []];
        yield 'all allowed (Composer 1 list form)' => [json_encode([
            ['name' => 'example/a', 'license' => ['ISC']],
            ['name' => 'example/b', 'license' => ['AGPL-3.0-only']],
        ], JSON_THROW_ON_ERROR), []];
        yield 'GPL-2.0-only' => [$v2([
            ['name' => 'example/ok', 'license' => ['MIT']],
            ['name' => 'example/gpl2', 'license' => ['GPL-2.0-only']],
        ]), ['example/gpl2']];
        yield 'proprietary' => [$v2([['name' => 'example/closed', 'license' => ['proprietary']]]), ['example/closed']];
        yield 'empty licence list' => [$v2([['name' => 'example/none', 'license' => []]]), ['example/none']];
        yield 'no licence key' => [$v2([['name' => 'example/missing']]), ['example/missing']];
        yield 'non-SPDX spelling' => [$v2([['name' => 'example/spelling', 'license' => ['BSD']]]), ['example/spelling']];
        yield 'deprecated plus form' => [$v2([['name' => 'example/plus', 'license' => ['GPL-3.0+']]]), ['example/plus']];
        yield 'LGPL 2.1 only' => [$v2([['name' => 'example/lgpl', 'license' => ['LGPL-2.1-only']]]), ['example/lgpl']];
        yield 'conjunction' => [$v2([['name' => 'example/and', 'license' => ['(MIT and GPL-2.0-only)']]]), ['example/and']];
        yield 'exception clause' => [$v2([['name' => 'example/with', 'license' => ['GPL-2.0-only WITH Classpath-exception-2.0']]]), ['example/with']];
        yield 'lower-case identifier' => [$v2([['name' => 'example/case', 'license' => ['mit']]]), ['example/case']];
    }

    /** @param list<string> $expectedNames */
    #[DataProvider('installedFixtures')]
    public function testLicenceViolationsAreDetected(string $json, array $expectedNames): void
    {
        $file = $this->writeTemp('installed.json', $json);
        $contents = file_get_contents($file);
        self::assertIsString($contents);

        $violations = self::licenceViolations(self::installedPackages($contents));
        $names = array_map(static fn (string $v): string => explode(':', $v, 2)[0], $violations);

        self::assertSame($expectedNames, $names);
    }

    public function testUnreadableInstalledListIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        self::installedPackages('{"dev": true}');
    }

    // ---------------------------------------------------- fixtures: record

    private const MANIFEST = '{"require":{"php":">=8.3","ext-pdo_sqlite":"*"},"require-dev":{"phpunit/phpunit":"12.5.43"}}';
    private const RUNTIME_MANIFEST = '{"require":{"php":">=8.3","example/runtime":"1.2.3"},"require-dev":{"phpunit/phpunit":"12.5.43"}}';
    private const PACKAGE_TABLE = "| Name | Version | Purpose | Licence | Verified from | Shipped |\n|---|---|---|---|---|---|\n";
    private const DEV_ROW = "| `phpunit/phpunit` | `12.5.43` (pinned exactly); installed 12.5.43 | Test runner | BSD-3-Clause | `vendor/phpunit/phpunit/LICENSE`, 2026-01-01 | No |\n";
    private const RUNTIME_ROW = "| `example/runtime` | `1.2.3` | Example | MIT | `LICENSE`, 2026-01-01 | Yes |\n";
    private const RUNTIME_NONE = "## Runtime dependencies (shipped)\n\nNone.\n";

    /** A synthetic record in the section 11 format; null runtime rows means "None." */
    private static function sampleRecord(?string $runtimeRows = null, string $devRows = self::DEV_ROW): string
    {
        $runtime = $runtimeRows === null
            ? self::RUNTIME_NONE
            : "## Runtime dependencies (shipped)\n\n" . self::PACKAGE_TABLE . $runtimeRows;

        return "# Dependencies\n\n## Rules\n\n- Allowed licences are those listed in `CLAUDE.md`.\n\n"
            . $runtime
            . "\n## Bundled font assets (shipped)\n\n"
            . "| Family | Files (directory) | Source | Licence | Verified from | Licence file shipped |\n"
            . "|---|---|---|---|---|---|\n"
            . "| Example Sans | `public/assets/fonts/example-sans/` | upstream 1.0 | OFL-1.1 | `LICENSE.txt`, 2026-01-01 | `public/assets/fonts/example-sans/LICENSE.txt` |\n"
            . "\n## Development dependencies (not shipped)\n\n"
            . self::PACKAGE_TABLE . $devRows
            . "\nTransitive development packages: 2.\n"
            . "\n## Checksums\n\n| File | Bytes | SHA-256 |\n|---|---|---|\n| `example-sans/regular.woff2` | 1,000 | `00` |\n";
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function recordFixtures(): iterable
    {
        $record = self::sampleRecord();
        $devLicence = static fn (string $licence): string => self::sampleRecord(
            null,
            str_replace('| BSD-3-Clause |', "| {$licence} |", self::DEV_ROW),
        );
        $devVersion = static fn (string $version): string => self::sampleRecord(
            null,
            str_replace('`12.5.43` (pinned exactly); installed 12.5.43', $version, self::DEV_ROW),
        );
        $runtimeSection = 'Runtime dependencies (shipped)';
        $devSection = 'Development dependencies (not shipped)';

        yield 'compliant' => [self::MANIFEST, $record, []];
        yield 'compliant with a runtime row' => [self::RUNTIME_MANIFEST, self::sampleRecord(self::RUNTIME_ROW), []];
        yield 'licence expression with an allowed operand' => [self::MANIFEST, $devLicence('MIT or GPL-2.0-only'), []];

        yield 'package licence not allowed' => [self::MANIFEST, $devLicence('GPL-2.0-only'), ["phpunit/phpunit: licence 'GPL-2.0-only' is not allowed"]];
        yield 'package licence not confirmed' => [self::MANIFEST, $devLicence('Not confirmed'), ["phpunit/phpunit: licence 'Not confirmed' is not allowed"]];
        yield 'package licence empty' => [self::MANIFEST, $devLicence(''), ["phpunit/phpunit: licence '' is not allowed"]];
        yield 'OFL-1.1 for a package' => [self::MANIFEST, $devLicence('OFL-1.1'), ['phpunit/phpunit: OFL-1.1 is allowed for bundled font assets only']];
        yield 'runtime licence not allowed' => [
            self::RUNTIME_MANIFEST,
            self::sampleRecord(str_replace('| MIT |', '| SSPL-1.0 |', self::RUNTIME_ROW)),
            ["example/runtime: licence 'SSPL-1.0' is not allowed"],
        ];
        yield 'font licence not allowed' => [
            self::MANIFEST,
            str_replace('| OFL-1.1 |', '| CC-BY-NC-4.0 |', $record),
            ["Example Sans: licence 'CC-BY-NC-4.0' is not allowed"],
        ];

        yield 'development package in the runtime table' => [
            self::MANIFEST,
            self::sampleRecord(self::DEV_ROW, ''),
            ["phpunit/phpunit: row in '{$runtimeSection}', but composer.json has it in require-dev (expected '{$devSection}')"],
        ];
        yield 'runtime package in the development table' => [
            self::RUNTIME_MANIFEST,
            self::sampleRecord(null, self::DEV_ROW . self::RUNTIME_ROW),
            ["example/runtime: row in '{$devSection}', but composer.json has it in require (expected '{$runtimeSection}')"],
        ];
        yield 'runtime package not recorded' => [self::RUNTIME_MANIFEST, $record, ["example/runtime: no row (expected in '{$runtimeSection}')"]];
        yield 'development package not recorded' => [
            self::MANIFEST,
            self::sampleRecord(null, str_replace('phpunit/phpunit', 'phpunit/phpunit-extra', self::DEV_ROW)),
            ["phpunit/phpunit: no row (expected in '{$devSection}')"],
        ];
        yield 'stale version' => [self::MANIFEST, $devVersion('`12.5.42`'), ['phpunit/phpunit: version cell does not give the composer.json constraint 12.5.43']];
        yield 'longer version only' => [self::MANIFEST, $devVersion('`12.5.431`'), ['phpunit/phpunit: version cell does not give the composer.json constraint 12.5.43']];

        yield 'missing category' => [self::MANIFEST, str_replace(self::RUNTIME_NONE, '', $record), ["missing section '{$runtimeSection}'"]];
        yield 'categories out of order' => [
            self::MANIFEST,
            str_replace(self::RUNTIME_NONE, '', $record) . "\n" . self::RUNTIME_NONE,
            ['the category sections must be in the order: ' . implode(', ', self::CATEGORIES)],
        ];
        yield 'licence table outside the categories' => [
            self::MANIFEST,
            $record . "\n## Other\n\n| Name | Licence |\n|---|---|\n| thing | MIT |\n",
            ["licence table outside the category sections, in 'Other'"],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('recordFixtures')]
    public function testRecordProblemsAreDetected(string $manifest, string $record, array $expected): void
    {
        $manifestFile = file_get_contents($this->writeTemp('composer.json', $manifest));
        $recordFile = file_get_contents($this->writeTemp('dependencies.md', $record));
        self::assertIsString($manifestFile);
        self::assertIsString($recordFile);

        self::assertSame($expected, self::recordProblems(self::directDependencies($manifestFile), $recordFile));
    }

    public function testTablesAreParsedWithTheirSection(): void
    {
        $tables = self::tables(self::sampleRecord());
        self::assertSame([self::FONTS, self::DEVELOPMENT, 'Checksums'], array_column($tables, 'section'));
        self::assertSame('phpunit/phpunit', $tables[1]['rows'][0][0]);
        self::assertCount(1, $tables[1]['rows'], 'The separator row is not a data row.');
    }

    public function testPlatformRequirementsAreNotPackages(): void
    {
        self::assertSame(
            ['require' => [], 'require-dev' => ['phpunit/phpunit' => '12.5.43']],
            self::directDependencies(self::MANIFEST),
        );
    }

    // -------------------------------------------------------------- helpers

    private function writeTemp(string $name, string $contents): string
    {
        if ($this->temp === null) {
            $dir = sys_get_temp_dir() . '/tiffindb-deps-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) {
                throw new RuntimeException('Cannot create a temporary directory.');
            }
            $this->temp = $dir;
        }
        $path = $this->temp . '/' . $name;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Cannot write {$name}.");
        }

        return $path;
    }
}
