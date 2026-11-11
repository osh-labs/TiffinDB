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
 * dependencies, lock setting, and scripts.
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

    public function testRuntimeRequiresOnlyPhp(): void
    {
        $require = self::manifest()['require'] ?? null;

        self::assertIsArray($require);
        self::assertSame(['php'], array_keys($require));
    }

    public function testDevelopmentRequiresOnlyPhpunit(): void
    {
        $requireDev = self::manifest()['require-dev'] ?? null;

        self::assertIsArray($requireDev);
        self::assertSame(['phpunit/phpunit'], array_keys($requireDev));
        // Exact pin (ADR 0008 decision 12): no range, caret, tilde, or wildcard.
        self::assertIsString($requireDev['phpunit/phpunit']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $requireDev['phpunit/phpunit']);
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
