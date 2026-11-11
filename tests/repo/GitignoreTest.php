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
 * T-REPO-1: the mandatory .gitignore entries exist, each as a whole line.
 */
#[CoversNothing]
final class GitignoreTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function requiredEntries(): iterable
    {
        foreach (['*.db', '*.sqlite*', '*.tiffin', '.env*', '/local/', '/data/', '/vendor/', '/composer.lock'] as $entry) {
            yield $entry => [$entry];
        }
    }

    #[DataProvider('requiredEntries')]
    public function testEntryIsAWholeLine(string $entry): void
    {
        $lines = preg_split('/\r\n|\n|\r/', RepoFiles::read('.gitignore'));
        self::assertIsArray($lines);

        self::assertContains($entry, $lines, "'.gitignore' must contain the line '{$entry}'.");
    }
}
