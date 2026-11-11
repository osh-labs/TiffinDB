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
 * `docs/dependencies.md` exists, every direct dependency in `composer.json`
 * has a line there giving its name and its exact constraint. While the file
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
     * Direct dependencies from a composer.json document, as name => constraint.
     * Platform requirements (php, ext-*, lib-*, composer-*) are not packages.
     *
     * @return array<string, string>
     */
    public static function directDependencies(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $result = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (is_array($data[$section] ?? null) ? $data[$section] : [] as $name => $constraint) {
                if ($name === 'php' || preg_match('/^(ext|lib|composer)-/', (string) $name) === 1) {
                    continue;
                }
                $result[(string) $name] = (string) $constraint;
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Direct dependencies that have no line in the record naming the package
     * together with its exact constraint.
     *
     * @param array<string, string> $dependencies
     * @return list<string>
     */
    public static function unrecorded(array $dependencies, string $record): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $record) ?: [];
        $found = [];
        foreach ($dependencies as $name => $constraint) {
            $namePattern = '/(?<![A-Za-z0-9_.\/-])' . preg_quote($name, '/') . '(?![A-Za-z0-9_\/-])/';
            $constraintPattern = '/(?<![A-Za-z0-9_.-])' . preg_quote($constraint, '/') . '(?![A-Za-z0-9_-]|\.\d)/';
            $recorded = false;
            foreach ($lines as $line) {
                if (preg_match($namePattern, $line) === 1 && preg_match($constraintPattern, $line) === 1) {
                    $recorded = true;
                    break;
                }
            }
            if (!$recorded) {
                $found[] = "{$name} {$constraint}";
            }
        }

        return $found;
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
            yield self::RECORD . ' exists: every direct dependency must be recorded' => [true];
        } else {
            yield self::RECORD . ' does not exist yet: the record check passes trivially' => [false];
        }
    }

    #[DataProvider('recordState')]
    public function testDirectDependenciesAreRecorded(bool $recordExists): void
    {
        $dependencies = self::directDependencies(RepoFiles::read('composer.json'));
        self::assertArrayHasKey('phpunit/phpunit', $dependencies);

        if (!$recordExists) {
            self::assertFileDoesNotExist(
                RepoFiles::path(self::RECORD),
                self::RECORD . ' does not exist yet, so there is no record to check.',
            );

            return;
        }

        self::assertSame(
            [],
            self::unrecorded($dependencies, RepoFiles::read(self::RECORD)),
            'Each direct dependency needs a line in ' . self::RECORD . ' with its name and exact constraint.',
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

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function recordFixtures(): iterable
    {
        $row = "| `phpunit/phpunit` | `12.5.43` (installed 12.5.43) | Test runner | BSD-3-Clause | vendor/phpunit/phpunit/LICENSE, 2026-01-01 | No |\n";

        yield 'recorded' => [self::MANIFEST, "# Dependencies\n\n## Development dependencies (not shipped)\n\n" . $row, []];
        yield 'not mentioned' => [self::MANIFEST, "# Dependencies\n\nNone.\n", ['phpunit/phpunit 12.5.43']];
        yield 'stale version' => [self::MANIFEST, str_replace('12.5.43', '12.5.42', $row), ['phpunit/phpunit 12.5.43']];
        yield 'longer version only' => [self::MANIFEST, str_replace('12.5.43', '12.5.431', $row), ['phpunit/phpunit 12.5.43']];
        yield 'name and version on different lines' => [self::MANIFEST, "phpunit/phpunit\n12.5.43\n", ['phpunit/phpunit 12.5.43']];
        yield 'similar package name only' => [self::MANIFEST, str_replace('phpunit/phpunit', 'phpunit/phpunit-extra', $row), ['phpunit/phpunit 12.5.43']];
        yield 'runtime dependency added but not recorded' => [
            '{"require":{"php":">=8.3","example/runtime":"1.2.3"},"require-dev":{"phpunit/phpunit":"12.5.43"}}',
            $row,
            ['example/runtime 1.2.3'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('recordFixtures')]
    public function testUnrecordedDependenciesAreDetected(string $manifest, string $record, array $expected): void
    {
        $manifestFile = file_get_contents($this->writeTemp('composer.json', $manifest));
        $recordFile = file_get_contents($this->writeTemp('dependencies.md', $record));
        self::assertIsString($manifestFile);
        self::assertIsString($recordFile);

        self::assertSame($expected, self::unrecorded(self::directDependencies($manifestFile), $recordFile));
    }

    public function testPlatformRequirementsAreNotPackages(): void
    {
        self::assertSame(['phpunit/phpunit' => '12.5.43'], self::directDependencies(self::MANIFEST));
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
