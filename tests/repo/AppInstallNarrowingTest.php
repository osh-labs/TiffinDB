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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TiffinDB\Tests\Support\RepoFiles;

require_once __DIR__ . '/CoreLayerRulesTest.php';

/**
 * ADR 0012 decision 1 as amended by P245: from Core\Install, Core\App may
 * name only RecoveryCode and SetupCode. CoreLayerRulesTest allows the whole
 * Install area; this narrows it to the two classes the decision names.
 */
#[CoversNothing]
final class AppInstallNarrowingTest extends TestCase
{
    private const ALLOWED = ['tiffindb\\core\\install\\recoverycode', 'tiffindb\\core\\install\\setupcode'];

    /** @return list<string> names of Core\Install in $source that are not one of the two allowed classes */
    public static function disallowed(string $source): array
    {
        $bad = [];
        foreach (CoreLayerRulesTest::namesIn($source) as $name) {
            $normal = strtolower(ltrim($name, '\\'));
            if (preg_match('/^tiffindb\\\\core\\\\install(?:\\\\|\z)/', $normal) !== 1) {
                continue;
            }
            if (!in_array($normal, self::ALLOWED, true)) {
                $bad[] = $name;
            }
        }

        return $bad;
    }

    public function testAppNamesOnlyRecoveryCodeAndSetupCodeFromInstall(): void
    {
        $root = RepoFiles::path('src/Core/App');
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::disallowed((string) file_get_contents($file->getPathname())) as $name) {
                $found[] = $file->getFilename() . ': ' . $name;
            }
        }
        self::assertSame([], $found);
    }

    /** @return iterable<string, array{string, int}> */
    public static function sources(): iterable
    {
        $head = "<?php\n\nnamespace TiffinDB\\Core\\App;\n\n";
        yield 'the two allowed' => [$head . "use TiffinDB\\Core\\Install\\RecoveryCode;\nuse TiffinDB\\Core\\Install\\SetupCode;\n", 0];
        yield 'the installer' => [$head . "use TiffinDB\\Core\\Install\\Installer;\n", 1];
        yield 'group use' => [$head . "use TiffinDB\\Core\\Install\\{SetupCode, DataFolder};\n", 1];
        yield 'fully qualified' => [$head . "\$x = \\TiffinDB\\Core\\Install\\Installer::class;\n", 1];
        yield 'bare namespace import' => [$head . "use TiffinDB\\Core\\Install;\n", 1];
        yield 'in a string' => [$head . "\$c = 'TiffinDB\\\\Core\\\\Install\\\\DataFolder';\n", 1];
    }

    #[DataProvider('sources')]
    public function testTheNarrowingFindsOtherInstallNames(string $source, int $expected): void
    {
        self::assertCount($expected, self::disallowed($source));
    }
}
