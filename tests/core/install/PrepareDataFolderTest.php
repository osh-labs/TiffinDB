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
use TiffinDB\Core\Install\DataFolder;
use TiffinDB\Core\Install\DataFolderResult;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallMessage;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Core\Install\SetupCode;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-INS-3 (outside the document root), T-INS-4 (inside, through the
 * installer), T-INS-6 (existing folders).
 */
#[CoversClass(Installer::class)]
#[CoversClass(DataFolderResult::class)]
#[CoversClass(InstallState::class)]
#[CoversClass(InstallMessage::class)]
final class PrepareDataFolderTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        mkdir($this->temp->path('www/app/public'), 0755, true);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function app(): string
    {
        return $this->temp->path('www/app');
    }

    private function sibling(): string
    {
        return $this->temp->path('www/tiffindb-data');
    }

    /** @param (\Closure(DataDirectory, Clock, Random): SystemDatabase)|null $createSystem */
    private function installer(?\Closure $createSystem = null, ?string $configured = null): Installer
    {
        return new Installer(
            $this->app(),
            $configured,
            new FixedClock(),
            new SeededRandom('tiffindb-prepare-test-seed-1'),
            new PasswordHasher(['bcrypt' => 4]),
            '0.1.0',
            $createSystem,
        );
    }

    /** A probe that fails the test if it is ever asked anything. */
    private function spy(): HttpProbe
    {
        return new class implements HttpProbe {
            public int $calls = 0;

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                $this->calls++;

                return new ProbeResponse(200, 'should not be asked');
            }
        };
    }

    /** @param \Closure(string): ?ProbeResponse $answer nonce => answer */
    private static function fake(string $dataDir, \Closure $answer): HttpProbe
    {
        return new class ($dataDir, $answer) implements HttpProbe {
            public int $calls = 0;

            public function __construct(private readonly string $dataDir, private readonly \Closure $answer)
            {
            }

            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                // The positive control in <app>/public/ is answered like a working server.
                $control = dirname($this->dataDir) . '/public/' . basename($path);
                if ($path === rtrim($target->basePath, '/') . '/' . basename($path) && str_starts_with(basename($path), 'probe-') && is_file($control)) {
                    return new ProbeResponse(200, (string) file_get_contents($control));
                }
                $this->calls++;
                $files = glob($this->dataDir . '/probe-*.txt') ?: [];

                return ($this->answer)($files === [] ? '' : (string) file_get_contents($files[0]));
            }
        };
    }

    private function target(string $documentRoot): ProbeTarget
    {
        return new ProbeTarget('example.test', '192.0.2.10', 80, false, $documentRoot, '/app');
    }

    public function testOutsideTheDocumentRootIsSafeWithoutAProbe(): void
    {
        $installer = $this->installer();
        $this->assertSame(InstallState::NeedsDataFolder, $installer->state());
        $spy = $this->spy();

        $result = $installer->prepareDataFolder($this->app() . '/public', $this->target($this->app() . '/public'), $spy);

        $this->assertEquals(new DataFolderResult('safe', '../tiffindb-data', false), $result);
        $this->assertSame(0, $spy->calls);
        $this->assertFileExists($this->sibling() . '/system.sqlite');
        $this->assertDirectoryExists($this->sibling() . '/projects');
        $this->assertSame(DataFolder::HTACCESS, file_get_contents($this->sibling() . '/.htaccess'));
        $this->assertSame(
            "# TiffinDB data folder. Nothing here may be served over the web.\nRequire all denied\n",
            file_get_contents($this->sibling() . '/.htaccess'),
        );
        $code = (string) file_get_contents($this->sibling() . '/setup-code.txt');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTVWXYZ2-9]{4}(-[ABCDEFGHJKMNPQRSTVWXYZ2-9]{4}){3}\n\z/', $code);
        $this->assertSame(InstallState::NeedsAdmin, $installer->state());
        $this->assertSame(InstallState::NeedsAdmin, $this->installer()->state());
        $this->assertEquals(new InstallMessage('setup.code_location', ['path' => '../tiffindb-data/setup-code.txt']), $installer->setupCodeLocation());
        $this->assertSame([], glob($this->sibling() . '/probe-*') ?: []);
    }

    public function testNoTargetIsNotNeededOutside(): void
    {
        $result = $this->installer()->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertSame('safe', $result->outcome);
    }

    public function testAFailedSystemDatabaseLeavesTheCodeAndASecondAttemptReplacesIt(): void
    {
        $failures = 1;
        $installer = $this->installer(static function (DataDirectory $data, Clock $clock, Random $random) use (&$failures): SystemDatabase {
            if ($failures-- > 0) {
                throw new \RuntimeException('Injected failure.');
            }

            return SystemDatabase::create($data, $clock, $random);
        });
        try {
            $installer->prepareDataFolder($this->app(), null, $this->spy());
            $this->fail('The injected failure should propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected failure.', $error->getMessage());
        }
        $first = file_get_contents($this->sibling() . '/setup-code.txt');
        $this->assertIsString($first);
        $this->assertFileDoesNotExist($this->sibling() . '/system.sqlite');
        $this->assertSame(InstallState::NeedsDataFolder, $installer->state());

        $result = $installer->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertSame('safe', $result->outcome);
        $second = file_get_contents($this->sibling() . '/setup-code.txt');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}\n\z/', (string) $second);
        $this->assertNotSame($first, $second, 'The code is replaced.');
        $this->assertSame(InstallState::NeedsAdmin, $installer->state());
    }

    public function testEnsureSetupCode(): void
    {
        $installer = $this->installer();
        $installer->prepareDataFolder($this->app(), null, $this->spy());
        $file = $this->sibling() . '/setup-code.txt';
        $original = file_get_contents($file);

        $installer->ensureSetupCode();
        $this->assertSame($original, file_get_contents($file), 'An existing code is never replaced.');

        file_put_contents($file, "KEEP-THIS-CODE-AS-IS\n");
        $installer->ensureSetupCode();
        $this->assertSame("KEEP-THIS-CODE-AS-IS\n", file_get_contents($file));

        unlink($file);
        $installer->ensureSetupCode();
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}\n\z/', (string) file_get_contents($file));
        $this->assertSame(0640, fileperms($file) & 0777);
    }

    public function testStepsForOtherStatesAreRefused(): void
    {
        $installer = $this->installer();
        foreach ([
            'ensureSetupCode' => static fn () => $installer->ensureSetupCode(),
            'setupCodeLocation' => static fn () => $installer->setupCodeLocation(),
            'createFirstAdmin' => static fn () => $installer->createFirstAdmin('x', 'Example Admin', 'admin', 'long enough password', '192.0.2.10'),
        ] as $name => $call) {
            try {
                $call();
                $this->fail($name . ' should be refused before the data folder exists.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $installer->prepareDataFolder($this->app(), null, $this->spy());
        $this->expectException(\LogicException::class);
        $installer->prepareDataFolder($this->app(), null, $this->spy());
    }

    public function testConfiguredFolderLocation(): void
    {
        $configured = $this->temp->path('elsewhere');
        $installer = $this->installer(null, $configured);
        $installer->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertFileExists($configured . '/system.sqlite');
        $this->assertEquals(new InstallMessage('setup.code_location.configured', ['file' => 'setup-code.txt']), $installer->setupCodeLocation());
    }

    /** @return iterable<string, array{\Closure(string): ?ProbeResponse, string}> */
    public static function insideAnswers(): iterable
    {
        yield 'served nonce' => [static fn (string $nonce): ?ProbeResponse => new ProbeResponse(200, $nonce), 'exposed'];
        yield '404' => [static fn (): ?ProbeResponse => new ProbeResponse(404, ''), 'safe'];
        yield '403' => [static fn (): ?ProbeResponse => new ProbeResponse(403, ''), 'safe'];
        yield '200 without nonce' => [static fn (): ?ProbeResponse => new ProbeResponse(200, 'a page'), 'safe'];
        yield '302' => [static fn (): ?ProbeResponse => new ProbeResponse(302, ''), 'inconclusive'];
        yield '500' => [static fn (): ?ProbeResponse => new ProbeResponse(500, ''), 'inconclusive'];
        yield 'null' => [static fn (): ?ProbeResponse => null, 'inconclusive'];
    }

    /** @param \Closure(string): ?ProbeResponse $answer */
    #[DataProvider('insideAnswers')]
    public function testInsideTheDocumentRoot(\Closure $answer, string $outcome): void
    {
        $root = $this->temp->path('www');
        $data = $this->app() . '/data';
        $installer = $this->installer();
        $fake = self::fake($data, $answer);

        $result = $installer->prepareDataFolder($root, $this->target($root), $fake);

        $this->assertSame($outcome, $result->outcome);
        $this->assertSame('data', $result->label);
        $this->assertTrue($result->inTree);
        $this->assertNull($result->failureKey);
        $this->assertGreaterThan(0, $fake->calls);
        $this->assertSame([], glob($data . '/probe-*') ?: [], 'The probe file is deleted.');
        $this->assertSame(DataFolder::HTACCESS, file_get_contents($data . '/.htaccess'));
        if ($outcome === 'safe') {
            $this->assertFileExists($data . '/system.sqlite');
            $this->assertFileExists($data . '/setup-code.txt');
            $this->assertSame(InstallState::NeedsAdmin, $installer->state());
        } else {
            $this->assertFileDoesNotExist($data . '/system.sqlite');
            $this->assertFileDoesNotExist($data . '/setup-code.txt');
            $this->assertSame(InstallState::NeedsDataFolder, $installer->state());
        }
    }

    public function testAnExposedFolderIsReusedByALaterAttempt(): void
    {
        $root = $this->temp->path('www');
        $data = $this->app() . '/data';
        $installer = $this->installer();
        $installer->prepareDataFolder($root, $this->target($root), self::fake($data, static fn (string $n): ?ProbeResponse => new ProbeResponse(200, $n)));

        $result = $installer->prepareDataFolder($root, $this->target($root), self::fake($data, static fn (): ?ProbeResponse => new ProbeResponse(403, '')));

        $this->assertSame('safe', $result->outcome);
    }

    public function testInsideWithoutATargetIsInconclusive(): void
    {
        $result = $this->installer()->prepareDataFolder(null, null, $this->spy());

        $this->assertEquals(new DataFolderResult('inconclusive', 'data', true), $result);
        $this->assertFileDoesNotExist($this->app() . '/data/system.sqlite');
    }

    public function testNoPlace(): void
    {
        $installer = new Installer(
            $this->temp->path('missing/app'),
            null,
            new FixedClock(),
            new SeededRandom('tiffindb-prepare-test-seed-2'),
            new PasswordHasher(['bcrypt' => 4]),
            '0.1.0',
        );

        $this->assertEquals(
            new DataFolderResult('failed', '../tiffindb-data', false, 'setup.data.no-place'),
            $installer->prepareDataFolder(null, null, $this->spy()),
        );
    }

    public function testAFolderWithUnknownFilesIsRefused(): void
    {
        mkdir($this->sibling());
        file_put_contents($this->sibling() . '/holiday-photos.txt', 'synthetic');

        $result = $this->installer()->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertEquals(new DataFolderResult('failed', '../tiffindb-data', false, 'setup.data.not-empty'), $result);
        $this->assertFileDoesNotExist($this->sibling() . '/.htaccess');
        $this->assertFileDoesNotExist($this->sibling() . '/setup-code.txt');
        $this->assertSame('synthetic', file_get_contents($this->sibling() . '/holiday-photos.txt'));
    }

    public function testAFolderWithAForeignHtaccessIsRefused(): void
    {
        mkdir($this->sibling());
        file_put_contents($this->sibling() . '/.htaccess', "Require all granted\n");

        $result = $this->installer()->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertSame('setup.data.not-empty', $result->failureKey);
        $this->assertSame("Require all granted\n", file_get_contents($this->sibling() . '/.htaccess'));
    }

    public function testAFolderHoldingOnlyOurHtaccessIsReused(): void
    {
        mkdir($this->sibling());
        file_put_contents($this->sibling() . '/.htaccess', DataFolder::HTACCESS);
        file_put_contents($this->sibling() . '/probe-0123456789abcdef.txt', 'stale');

        $result = $this->installer()->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertSame('safe', $result->outcome);
        $this->assertFileExists($this->sibling() . '/system.sqlite');
        $this->assertFileDoesNotExist($this->sibling() . '/probe-0123456789abcdef.txt');
    }

    public function testStaleControlFilesInPublicAreRemovedAndNothingElse(): void
    {
        $public = $this->app() . '/public';
        mkdir($public . '/assets/css', 0755, true);
        file_put_contents($public . '/probe-0123456789abcdef.txt', 'stale control');
        file_put_contents($public . '/probe-notes.txt', 'not ours');
        file_put_contents($public . '/assets/css/fonts.css', 'shipped');
        file_put_contents($public . '/index.php', '<?php');

        $this->installer()->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertFileDoesNotExist($public . '/probe-0123456789abcdef.txt');
        $this->assertFileExists($public . '/probe-notes.txt');
        $this->assertFileExists($public . '/assets/css/fonts.css');
        $this->assertFileExists($public . '/index.php');
    }

    public function testCodeIsWrittenBeforeTheSystemDatabase(): void
    {
        $seen = null;
        $installer = $this->installer(static function (DataDirectory $data, Clock $clock, Random $random) use (&$seen): SystemDatabase {
            $seen = [
                is_file($data->path() . '/' . SetupCode::FILE),
                is_dir($data->path() . '/projects'),
            ];

            return SystemDatabase::create($data, $clock, $random);
        });
        $installer->prepareDataFolder($this->app(), null, $this->spy());

        $this->assertSame([true, true], $seen);
    }
}
