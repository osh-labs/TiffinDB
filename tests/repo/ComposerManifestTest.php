<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-REPO-2: the Composer manifest has the agreed identity, licence,
 * dependencies (each package pinned exactly, ADR 0016), lock setting, and
 * scripts.
 */
#[CoversNothing]
final class ComposerManifestTest extends TestCase
{
    /** One exact release: digits only, and \z so a trailing newline is refused too. */
    private const EXACT_PIN = '/^\d+\.\d+\.\d+\z/';

    public static function isExactPin(mixed $constraint): bool
    {
        return is_string($constraint) && preg_match(self::EXACT_PIN, $constraint) === 1;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $data = json_decode(RepoFiles::read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testNameAndLicence(): void
    {
        $manifest = self::manifest();

        self::assertSame('tiffindb/tiffindb', $manifest['name'] ?? null);
        self::assertSame('AGPL-3.0-or-later', $manifest['license'] ?? null);
    }

    public function testRuntimeRequiresPhpPdoSqliteAndThePinnedXlsxLibrary(): void
    {
        $require = self::manifest()['require'] ?? null;

        self::assertIsArray($require);
        self::assertSame(['php', 'ext-pdo_sqlite', 'openspout/openspout'], array_keys($require));
        // M1 spec section 4.1: the core stores everything in SQLite through PDO.
        self::assertSame('*', $require['ext-pdo_sqlite']);
        // ADR 0016 decision 2: the only shipped package, pinned exactly.
        self::assertSame('5.2.2', $require['openspout/openspout']);
    }

    public function testDevelopmentRequiresOnlyPhpunit(): void
    {
        $requireDev = self::manifest()['require-dev'] ?? null;

        self::assertIsArray($requireDev);
        self::assertSame(['phpunit/phpunit'], array_keys($requireDev));
    }

    public function testEveryPackageIsPinnedExactly(): void
    {
        // ADR 0008 decision 12: no lock file, so every package (not platform
        // requirements such as php or ext-*) names one exact release: no
        // range, caret, tilde, wildcard, alias, branch, or stability flag.
        $manifest = self::manifest();
        $packages = 0;
        foreach (['require', 'require-dev'] as $section) {
            self::assertIsArray($manifest[$section] ?? null);
            foreach ($manifest[$section] as $name => $constraint) {
                if ($name === 'php' || preg_match('/^(ext|lib|composer)-/', (string) $name) === 1) {
                    continue;
                }
                ++$packages;
                self::assertTrue(self::isExactPin($constraint), "{$section}.{$name} must be pinned exactly.");
            }
        }
        self::assertSame(2, $packages);
    }

    public function testLockFileIsDisabled(): void
    {
        $config = self::manifest()['config'] ?? null;

        self::assertIsArray($config);
        self::assertArrayHasKey('lock', $config);
        self::assertFalse($config['lock']);
    }

    public function testScriptsExist(): void
    {
        $scripts = self::manifest()['scripts'] ?? null;

        self::assertIsArray($scripts);
        foreach (['lint', 'test', 'check'] as $name) {
            self::assertArrayHasKey($name, $scripts, "Composer script '{$name}' is missing.");
        }
    }

    public function testNameAndDescriptionAvoidTheLegacyProductName(): void
    {
        // Built from character codes so that the name itself never appears
        // in the source tree.
        $legacy = implode('', array_map('chr', [98, 101, 110, 116, 111]));
        $manifest = self::manifest();

        foreach (['name', 'description'] as $field) {
            $value = $manifest[$field] ?? '';
            self::assertIsString($value);
            self::assertStringNotContainsStringIgnoringCase(
                $legacy,
                $value,
                "composer.json '{$field}' must not contain the legacy product's name.",
            );
        }
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function pinFixtures(): iterable
    {
        yield 'exact' => ['5.2.2', true];
        yield 'exact, two-digit parts' => ['12.5.43', true];
        yield 'caret' => ['^5.2.2', false];
        yield 'tilde' => ['~5.2.2', false];
        yield 'wildcard' => ['*', false];
        yield 'wildcard patch' => ['5.2.*', false];
        yield 'lower bound' => ['>=5.2.2', false];
        yield 'bounded range' => ['>=5.2.2 <5.3', false];
        yield 'hyphen range' => ['5.2.2 - 5.3.0', false];
        yield 'alternatives' => ['5.2.2 || 5.3.0', false];
        yield 'not equal' => ['!=5.2.1', false];
        yield 'equals operator' => ['==5.2.2', false];
        yield 'v prefix' => ['v5.2.2', false];
        yield 'two parts' => ['5.2', false];
        yield 'four parts' => ['5.2.2.0', false];
        yield 'branch' => ['dev-master', false];
        yield 'stability flag' => ['5.2.2@dev', false];
        yield 'pre-release' => ['5.2.2-beta1', false];
        yield 'alias' => ['5.2.2 as 5.3.0', false];
        yield 'trailing newline' => ["5.2.2\n", false];
        yield 'surrounding space' => [' 5.2.2', false];
        yield 'empty' => ['', false];
        yield 'not a string' => [522, false];
    }

    #[DataProvider('pinFixtures')]
    public function testThePinRuleRefusesRangesAndWildcards(mixed $constraint, bool $exact): void
    {
        self::assertSame($exact, self::isExactPin($constraint));
    }
}
