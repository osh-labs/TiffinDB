<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\DataCandidate;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Tests\Support\TempDir;

/** T-STO-6: candidate order and labels, locate, configured override, project folders. */
#[CoversClass(DataDirectory::class)]
#[CoversClass(DataCandidate::class)]
final class DataDirectoryTest extends TestCase
{
    private TempDir $temp;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @param list<DataCandidate> $candidates @return list<array{string, string, bool}> */
    private static function flat(array $candidates): array
    {
        return array_map(static fn (DataCandidate $c): array => [$c->path, $c->label, $c->inTree], $candidates);
    }

    public function testCandidatesInSearchOrder(): void
    {
        self::assertSame(
            [
                ['/srv/www/tiffindb-data', '../tiffindb-data', false],
                ['/srv/www/app/data', 'data', true],
            ],
            self::flat(DataDirectory::candidates('/srv/www/app', null)),
        );
        self::assertSame(
            self::flat(DataDirectory::candidates('/srv/www/app', null)),
            self::flat(DataDirectory::candidates('/srv/www/app/', '')),
            'A trailing slash and an empty configured value change nothing.',
        );
    }

    public function testConfiguredPathIsTheOnlyCandidate(): void
    {
        self::assertSame(
            [['/var/lib/tiffindb', 'the folder set in config/local.php', false]],
            self::flat(DataDirectory::candidates('/srv/www/app', '/var/lib/tiffindb/')),
        );
        self::assertSame(
            [['/srv/www/private/tiffin', 'the folder set in config/local.php', false]],
            self::flat(DataDirectory::candidates('/srv/www/app', '../private/./tiffin')),
            'A relative configured path is relative to the application folder.',
        );
        self::assertSame(
            [['/srv/www/app/data', 'the folder set in config/local.php', true]],
            self::flat(DataDirectory::candidates('/srv/www/app', 'data')),
        );
    }

    public function testLocateFindsTheFirstCandidateHoldingTheSystemDatabase(): void
    {
        $app = $this->temp->path('app');
        mkdir($app);
        mkdir($app . '/data');
        mkdir($this->temp->path('tiffindb-data'));

        self::assertNull(DataDirectory::locate($app, null), 'No system.sqlite anywhere.');

        file_put_contents($app . '/data/system.sqlite', '');
        $found = DataDirectory::locate($app, null);
        self::assertNotNull($found);
        self::assertSame($app . '/data', $found->path());
        self::assertSame('data', $found->label());
        self::assertSame($app . '/data/system.sqlite', $found->systemDatabase());

        file_put_contents($this->temp->path('tiffindb-data/system.sqlite'), '');
        $found = DataDirectory::locate($app, null);
        self::assertNotNull($found);
        self::assertSame($this->temp->path('tiffindb-data'), $found->path());
        self::assertSame('../tiffindb-data', $found->label());
    }

    public function testConfiguredPathOverridesTheDefaults(): void
    {
        $app = $this->temp->path('app');
        mkdir($app);
        mkdir($app . '/data');
        file_put_contents($app . '/data/system.sqlite', '');
        $configured = $this->temp->path('elsewhere');
        mkdir($configured);

        self::assertNull(DataDirectory::locate($app, $configured), 'Only the configured folder is searched.');

        file_put_contents($configured . '/system.sqlite', '');
        $found = DataDirectory::locate($app, $configured);
        self::assertNotNull($found);
        self::assertSame($configured, $found->path());
        self::assertSame('the folder set in config/local.php', $found->label());
    }

    public function testADirectoryNamedSystemSqliteDoesNotCount(): void
    {
        $app = $this->temp->path('app');
        mkdir($app);
        mkdir($app . '/data');
        mkdir($app . '/data/system.sqlite');
        self::assertNull(DataDirectory::locate($app, null));
    }

    public function testProjectFolder(): void
    {
        $data = new DataDirectory('/srv/tiffindb-data/', '../tiffindb-data');
        self::assertSame('/srv/tiffindb-data', $data->path());
        self::assertSame('/srv/tiffindb-data/projects/q7mzk2wbx4ae', $data->projectFolder('q7mzk2wbx4ae'));

        $candidate = new DataCandidate('/srv/app/data', 'data', true);
        self::assertSame('/srv/app/data', DataDirectory::fromCandidate($candidate)->path());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'parent' => ['..'];
        yield 'traversal' => ['../../etc'];
        yield 'slash' => ['q7mzk2/bx4ae'];
        yield 'upper case' => ['Q7MZK2WBX4AE'];
        yield 'short' => ['q7mzk'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidKeys')]
    public function testProjectFolderRejectsAnInvalidKey(string $key): void
    {
        $data = new DataDirectory('/srv/tiffindb-data', '../tiffindb-data');
        $this->expectException(InvalidArgumentException::class);
        $data->projectFolder($key);
    }

    public function testRelativeApplicationRootIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DataDirectory::candidates('app', null);
    }
}
