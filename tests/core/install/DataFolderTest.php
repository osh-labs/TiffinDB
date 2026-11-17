<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Install\DataFolder;
use TiffinDB\Core\Install\SetupCode;
use TiffinDB\Tests\Support\TempDir;

/** T-INS-2 (choosing the data folder) and the leftover rules behind T-INS-6. */
#[CoversClass(DataFolder::class)]
final class DataFolderTest extends TestCase
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

    public function testDocumentRootIsTheApplicationFolder(): void
    {
        $chosen = DataFolder::choose($this->app(), null, $this->app());

        $this->assertNotNull($chosen);
        $this->assertSame('../tiffindb-data', $chosen->label);
        $this->assertSame($this->temp->path('www/tiffindb-data'), $chosen->path);
        $this->assertFalse($chosen->inTree);
        $this->assertTrue(DataFolder::isOutside($chosen->path, $this->app()));
    }

    public function testDocumentRootIsThePublicFolder(): void
    {
        $chosen = DataFolder::choose($this->app(), null, $this->app() . '/public');

        $this->assertSame('../tiffindb-data', $chosen?->label);
    }

    public function testDocumentRootAboveTheApplicationFolderChoosesData(): void
    {
        $chosen = DataFolder::choose($this->app(), null, $this->temp->path('www'));

        $this->assertNotNull($chosen);
        $this->assertSame('data', $chosen->label);
        $this->assertSame($this->app() . '/data', $chosen->path);
        $this->assertTrue($chosen->inTree);
        $this->assertFalse(DataFolder::isOutside($chosen->path, $this->temp->path('www')));
    }

    public function testUnknownDocumentRootChoosesData(): void
    {
        $chosen = DataFolder::choose($this->app(), null, null);

        $this->assertSame('data', $chosen?->label);
        $this->assertFalse(DataFolder::isOutside($this->temp->path('www/tiffindb-data'), null));
    }

    public function testConfiguredPathIsTheOnlyCandidate(): void
    {
        $configured = $this->temp->path('elsewhere');
        $chosen = DataFolder::choose($this->app(), $configured, $this->app());

        $this->assertNotNull($chosen);
        $this->assertSame($configured, $chosen->path);
        $this->assertSame('the folder set in config/local.php', $chosen->label);

        $this->assertNull(DataFolder::choose($this->app(), $this->temp->path('missing/deeper'), $this->app()));
    }

    public function testNothingWritableGivesNone(): void
    {
        $app = $this->temp->path('missing/app');

        $this->assertNull(DataFolder::choose($app, null, null));
        $this->assertNull(DataFolder::choose($app, null, $app));
        $this->assertSame('../tiffindb-data', DataFolder::firstCandidate($app, null)->label);
    }

    public function testSiblingNotUsableFallsBackToData(): void
    {
        // A file where the sibling folder would be.
        file_put_contents($this->temp->path('www/tiffindb-data'), 'x');

        $this->assertSame('data', DataFolder::choose($this->app(), null, $this->app())?->label);
    }

    public function testAnUnresolvableDocumentRootCountsAsInside(): void
    {
        $this->assertFalse(DataFolder::isOutside($this->temp->path('www/tiffindb-data'), "/no\0where"));
        $this->assertFalse(DataFolder::isOutside($this->temp->path('www/x/../tiffindb-data/../y'), $this->app()));
        $this->assertSame('data', DataFolder::choose($this->app(), null, "/no\0where")?->label);
    }

    public function testLeftoversAreRecognised(): void
    {
        $folder = $this->temp->path('data');
        mkdir($folder);
        $this->assertTrue(DataFolder::holdsOnlyLeftovers($folder));

        file_put_contents($folder . '/.htaccess', DataFolder::HTACCESS);
        mkdir($folder . '/projects');
        file_put_contents($folder . '/' . SetupCode::FILE, "ABCD-EFGH-JKMN-PQRS\n");
        file_put_contents($folder . '/probe-0123456789abcdef.txt', 'nonce');
        $this->assertTrue(DataFolder::holdsOnlyLeftovers($folder));
        $this->assertSame(['probe-0123456789abcdef.txt'], DataFolder::probeFiles($folder));
    }

    public function testAnythingElseIsNotALeftover(): void
    {
        $cases = [
            'unknown file' => static fn (string $f): bool => file_put_contents($f . '/notes.txt', 'x') !== false,
            'changed .htaccess' => static fn (string $f): bool => file_put_contents($f . '/.htaccess', "Require all granted\n") !== false,
            'projects with something in it' => static fn (string $f): bool => mkdir($f . '/projects/abcdefghijkm', 0750, true),
            'projects as a file' => static fn (string $f): bool => file_put_contents($f . '/projects', 'x') !== false,
            'probe name not hex' => static fn (string $f): bool => file_put_contents($f . '/probe-0123456789abcdeg.txt', 'x') !== false,
            'system database' => static fn (string $f): bool => file_put_contents($f . '/system.sqlite', 'x') !== false,
            'setup code as a folder' => static fn (string $f): bool => mkdir($f . '/setup-code.txt'),
            'symbolic link' => static fn (string $f): bool => symlink('/etc/hostname', $f . '/setup-code.txt'),
        ];
        foreach ($cases as $name => $make) {
            $folder = $this->temp->path('case-' . md5($name));
            mkdir($folder);
            $this->assertTrue($make($folder), $name);
            $this->assertFalse(DataFolder::holdsOnlyLeftovers($folder), $name);
        }
    }

    public function testHtaccessText(): void
    {
        $this->assertSame(
            "# TiffinDB data folder. Nothing here may be served over the web.\nRequire all denied\n",
            DataFolder::HTACCESS,
        );
    }
}
