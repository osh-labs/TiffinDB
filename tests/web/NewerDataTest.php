<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\HistoricFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\CoreServices;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/EndToEndTest.php';

/**
 * T-UPG-3 and T-UPG-4 (M7 spec part 1 sections 12.2 and 12.3): data
 * written by a newer TiffinDB is refused through the kernel with a plain
 * 503 page, and nothing in the data folder changes. The installation is a
 * HistoricFixture data folder at the current versions, then marked newer:
 * the system database at schema version 3, or the project file at format
 * version 5. The kernel runs over the real CoreServices, as EndToEndTest.
 */
#[CoversNothing]
final class NewerDataTest extends TestCase
{
    private TempDir $temp;
    private HistoricFixture $fixture;
    private NoProbeServices $services;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->temp = TempDir::create('tiffindb-newer-data-');
        mkdir($this->temp->path('data'), 0750);
        $this->fixture = HistoricFixture::build($this->temp->path('data'), 2, 4);
        $config = Fixtures::config(false) + ['data_dir' => $this->fixture->data->path()];
        $this->services = new NoProbeServices(new CoreServices(
            $config,
            EndToEndTest::appRoot(),
            new FixedClock('2026-11-25T12:00:00Z'),
            new SeededRandom('tiffindb-newer-data-test-1'),
            new PasswordHasher(['bcrypt' => 4]),
        ));
        $this->kernel = new Kernel($config, EndToEndTest::appRoot(), null, $this->services);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
        self::assertSame(0, $this->services->probeRequests, 'The exposure probe is never asked for a file.');
    }

    /** @return array<string, string> every file of the data folder => its SHA-256 */
    private function files(): array
    {
        $root = $this->fixture->data->path();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return $files;
    }

    private static function assertRefused(string $title, string $body, Response $response): void
    {
        self::assertSame(503, $response->status);
        self::assertSame($title, HtmlAssertions::text($response->body, '//main//h1'));
        self::assertStringContainsString($body, HtmlAssertions::text($response->body, '//main'));
        self::assertStringContainsString($title . ' - TiffinDB', HtmlAssertions::text($response->body, '//title'));
    }

    // ------------------------------------------------------------ T-UPG-3

    public function testASystemDatabaseFromANewerVersionIsRefusedAndLeftUnchanged(): void
    {
        $this->fixture->makeSystemNewer();
        $before = $this->files();
        self::assertArrayHasKey('system.sqlite', $before);

        $client = new EndToEndClient($this->kernel);
        foreach (['/', '/sign-in', '/projects'] as $path) {
            self::assertRefused(
                'This TiffinDB needs a newer version',
                'Its data folder was written by a newer version of TiffinDB. Nothing was changed. Upload that newer version, or the one you upgraded from, to open it again.',
                $client->get($path),
            );
        }

        self::assertSame($before, $this->files(), 'No file of the data folder changed, and no copy was made.');
        self::assertSame(3, HistoricFixture::systemVersion($this->fixture->data->systemDatabase()));
    }

    // ------------------------------------------------------------ T-UPG-4

    public function testAProjectFromANewerVersionIsRefusedAndLeftUnchanged(): void
    {
        $this->fixture->makeProjectNewer();
        $file = 'projects/' . $this->fixture->projectKey . '/project.tiffin';

        $member = new EndToEndClient($this->kernel);
        self::assertSame(200, $member->get('/sign-in')->status);
        self::assertSame(303, $member->submit('/sign-in', ['username' => 'member', 'password' => HistoricFixture::PASSWORD])->status);
        $before = $this->files();
        self::assertArrayHasKey($file, $before);

        $projectPath = '/projects/' . $this->fixture->projectKey;
        foreach ([$projectPath . '/tables/' . HistoricFixture::ITEMS, $this->fixture->recordPath('Toaster')] as $path) {
            self::assertRefused(
                'This project needs a newer TiffinDB',
                'It was saved by a newer version of TiffinDB. Update TiffinDB to open it.',
                $member->get($path),
            );
        }

        $after = $this->files();
        self::assertSame($before[$file], $after[$file], 'The project file is unchanged.');
        // The empty lock file the project lock opens (M7 spec section 4) is the only addition.
        $inProject = static fn (array $files): array => array_filter(
            $files,
            static fn (string $name): bool => str_starts_with($name, 'projects/') && !str_ends_with($name, '/project.lock'),
            ARRAY_FILTER_USE_KEY,
        );
        self::assertSame($inProject($before), $inProject($after), 'No snapshot or other project file was written.');
        self::assertSame(5, HistoricFixture::userVersion($this->fixture->projectFile()));
    }
}
