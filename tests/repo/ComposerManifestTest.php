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

/**
 * T-REPO-2: the Composer manifest has the agreed identity, licence,
 * dependencies (each package pinned exactly, ADR 0016), lock setting, and
 * scripts.
 */
#[CoversNothing]
final class ComposerManifestTest extends TestCase
{
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
                self::assertIsString($constraint, "{$section}.{$name}");
                self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $constraint, "{$section}.{$name} must be pinned exactly.");
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
}
