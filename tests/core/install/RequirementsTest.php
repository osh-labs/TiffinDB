<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\Requirement;
use TiffinDB\Core\Install\RequirementEnvironment;
use TiffinDB\Core\Install\RequirementReport;
use TiffinDB\Core\Install\ServerEnvironment;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-INS-1: the requirement check with a fake environment. */
#[CoversClass(Installer::class)]
#[CoversClass(Requirement::class)]
#[CoversClass(RequirementReport::class)]
#[CoversClass(ServerEnvironment::class)]
final class RequirementsTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        mkdir($this->temp->path('app'));
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @param array<string, mixed> $overrides */
    private static function environment(array $overrides = []): RequirementEnvironment
    {
        $values = $overrides + [
            'id' => 80312,
            'version' => '8.3.12',
            'extensions' => ['pdo_sqlite', 'zip', 'xmlreader'],
            'sqlite' => '3.45.1',
            'random' => true,
        ];

        return new class ($values) implements RequirementEnvironment {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function phpVersionId(): int
            {
                return $this->values['id'];
            }

            public function phpVersion(): string
            {
                return $this->values['version'];
            }

            public function extensionLoaded(string $name): bool
            {
                return in_array($name, $this->values['extensions'], true);
            }

            public function sqliteVersion(): ?string
            {
                return $this->values['sqlite'];
            }

            public function randomWorks(): bool
            {
                return $this->values['random'];
            }
        };
    }

    private function installer(?PasswordHasher $hasher = null, ?string $appRoot = null): Installer
    {
        return new Installer(
            $appRoot ?? $this->temp->path('app'),
            null,
            new FixedClock(),
            new SeededRandom('tiffindb-requirements-test-seed-1'),
            $hasher ?? new PasswordHasher(['bcrypt' => 4]),
            '0.1.0',
        );
    }

    /** @return array<string, array{string, string, array<string, string|int>}> */
    private static function rows(RequirementReport $report): array
    {
        $rows = [];
        foreach ($report->requirements as $row) {
            $rows[$row->id] = [$row->status, $row->key, $row->params];
        }

        return $rows;
    }

    public function testEverythingPassingOnHttps(): void
    {
        $report = $this->installer()->requirements(true, $this->temp->path('app/public'), self::environment());

        $this->assertSame([
            'php' => ['ok', 'setup.check.php.ok', ['version' => '8.3.12']],
            'sqlite' => ['ok', 'setup.check.sqlite.ok', ['version' => '3.45.1']],
            'zip' => ['ok', 'setup.check.zip.ok', []],
            'xmlreader' => ['ok', 'setup.check.xmlreader.ok', []],
            'random' => ['ok', 'setup.check.random.ok', []],
            'passwords' => ['warning', 'setup.check.passwords.bcrypt', []],
            'https' => ['ok', 'setup.check.https.ok', []],
            'data' => ['ok', 'setup.check.data.outside', ['label' => '../tiffindb-data']],
        ], self::rows($report));
        $this->assertTrue($report->canContinue());
        $this->assertSame('setup.check.php.ok', $report->get('php')?->key);
        $this->assertNull($report->get('nothing'));
    }

    public function testWarningsDoNotStopSetup(): void
    {
        $report = $this->installer()->requirements(false, null, self::environment());
        $rows = self::rows($report);

        $this->assertSame(['warning', 'setup.check.https.warning', []], $rows['https']);
        $this->assertSame(['ok', 'setup.check.data.in-tree', ['label' => 'data']], $rows['data']);
        $this->assertTrue($report->canContinue());
    }

    public function testArgon2idIsReportedAsOk(): void
    {
        if (!PasswordHasher::argon2idAvailable()) {
            $this->markTestSkipped('Argon2id is not available in this PHP.');
        }
        $hasher = new PasswordHasher(['argon2id' => ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]]);
        $rows = self::rows($this->installer($hasher)->requirements(true, null, self::environment()));

        $this->assertSame(['ok', 'setup.check.passwords.argon2id', []], $rows['passwords']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string, array<string, string|int>}> */
    public static function failures(): iterable
    {
        yield 'old PHP' => [['id' => 80230, 'version' => '8.2.30'], 'php', 'failed', 'setup.check.php.failed', ['version' => '8.2.30']];
        yield 'PHP 8.3.0 passes' => [['id' => 80300, 'version' => '8.3.0'], 'php', 'ok', 'setup.check.php.ok', ['version' => '8.3.0']];
        yield 'no pdo_sqlite' => [['extensions' => ['zip', 'xmlreader'], 'sqlite' => null], 'sqlite', 'failed', 'setup.check.sqlite.missing', []];
        yield 'pdo_sqlite loaded, no version' => [['sqlite' => null], 'sqlite', 'failed', 'setup.check.sqlite.missing', []];
        yield 'SQLite 3.35.4' => [['sqlite' => '3.35.4'], 'sqlite', 'failed', 'setup.check.sqlite.old', ['version' => '3.35.4']];
        yield 'SQLite 3.35.5' => [['sqlite' => '3.35.5'], 'sqlite', 'ok', 'setup.check.sqlite.ok', ['version' => '3.35.5']];
        yield 'no zip' => [['extensions' => ['pdo_sqlite', 'xmlreader']], 'zip', 'failed', 'setup.check.zip.failed', []];
        yield 'no xmlreader' => [['extensions' => ['pdo_sqlite', 'zip']], 'xmlreader', 'failed', 'setup.check.xmlreader.failed', []];
        yield 'no random' => [['random' => false], 'random', 'failed', 'setup.check.random.failed', []];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, string|int> $params
     */
    #[DataProvider('failures')]
    public function testEachCheck(array $overrides, string $id, string $status, string $key, array $params): void
    {
        $report = $this->installer()->requirements(true, null, self::environment($overrides));
        $rows = self::rows($report);

        $this->assertSame([$status, $key, $params], $rows[$id]);
        $this->assertSame($status !== 'failed', $report->canContinue());
    }

    public function testNoPlaceForTheDataFolderFails(): void
    {
        $report = $this->installer(null, $this->temp->path('missing/app'))->requirements(true, null, self::environment());

        $this->assertSame(['failed', 'setup.check.data.failed', ['label' => '../tiffindb-data']], self::rows($report)['data']);
        $this->assertFalse($report->canContinue());
    }

    public function testRowOrder(): void
    {
        $report = $this->installer()->requirements(true, null, self::environment());

        $this->assertSame(
            ['php', 'sqlite', 'zip', 'xmlreader', 'random', 'passwords', 'https', 'data'],
            array_map(static fn (Requirement $r): string => $r->id, $report->requirements),
        );
    }

    public function testTheServerEnvironmentReadsRealValues(): void
    {
        $env = new ServerEnvironment();

        $this->assertSame(PHP_VERSION_ID, $env->phpVersionId());
        $this->assertSame(PHP_VERSION, $env->phpVersion());
        $this->assertTrue($env->extensionLoaded('pdo_sqlite'));
        $this->assertFalse($env->extensionLoaded('no_such_extension_here'));
        $this->assertIsString($env->sqliteVersion());
        $this->assertTrue($env->randomWorks());
    }

    public function testUnknownStatusIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Requirement('php', 'fine', 'setup.check.php.ok');
    }
}
