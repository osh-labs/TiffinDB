<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use TiffinDB\Core\App\ProjectOperations;
use TiffinDB\Core\Storage\DataDirectory;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-CF-8 (M4 CF-7, an M2 test gap): ProjectOperations::create fails
 * closed when the data folder's `projects/` is a symbolic link (to a
 * folder, or dangling) or a file: it is never followed, nothing is
 * written where it points, and no registry row is left behind.
 */
#[CoversClass(ProjectOperations::class)]
final class ProjectLinkFolderTest extends AppTestCase
{
    /** @return iterable<string, array{string}> */
    public static function shapes(): iterable
    {
        yield 'a link to a folder' => ['folder'];
        yield 'a dangling link' => ['dangling'];
        yield 'a file' => ['file'];
    }

    #[DataProvider('shapes')]
    public function testCreateFailsClosed(string $shape): void
    {
        $projects = $this->fx->data->path() . '/' . DataDirectory::PROJECTS;
        if (is_dir($projects) && !is_link($projects)) {
            self::assertSame([], array_values(array_diff((array) scandir($projects), ['.', '..'])), 'The fixture starts with no project.');
            rmdir($projects);
        }
        $elsewhere = $this->fx->temp->path('elsewhere');
        mkdir($elsewhere, 0o700);
        match ($shape) {
            'folder' => symlink($elsewhere, $projects),
            'dangling' => symlink($this->fx->temp->path('nothing-here'), $projects),
            'file' => file_put_contents($projects, 'synthetic'),
        };
        $this->captureLog();

        $failed = null;
        try {
            $this->ops->projects()->create($this->admin, 'Example Home', 'Rooms');
        } catch (\RuntimeException $error) {
            $failed = $error;
        }
        self::assertNotNull($failed, 'Creating a project through a linked projects folder must fail.');

        self::assertSame([], array_values(array_diff((array) scandir($elsewhere), ['.', '..'])), 'Nothing is written where the link points.');
        self::assertFileDoesNotExist($this->fx->temp->path('nothing-here'));
        self::assertSame($shape === 'file', is_file($projects) && !is_link($projects));
        self::assertSame($shape !== 'file', is_link($projects), 'The link is left as it was.');
        self::assertSame([], $this->ops->projects()->listFor($this->admin), 'No project is listed.');
        self::assertSame([], $this->fx->systemRows()['projects'], 'No registry row is left.');
    }
}
