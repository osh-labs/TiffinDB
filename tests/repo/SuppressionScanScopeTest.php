<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Repo;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/NoErrorSuppressionTest.php';

/**
 * M7-WP0 review: the directory list of the error-suppression scan keeps
 * every scanned root, tools/ included (M7 spec part 1 section 15.1,
 * CF7-4), so the scan cannot lose a root without a failing test.
 */
#[CoversNothing]
final class SuppressionScanScopeTest extends TestCase
{
    public function testTheScanListsEveryRoot(): void
    {
        foreach (['src', 'plugins', 'templates', 'tools'] as $root) {
            self::assertContains($root, NoErrorSuppressionTest::DIRECTORIES, $root);
        }
    }
}
