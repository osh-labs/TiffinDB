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
use TiffinDB\Core\Install\RecoveryCode;
use TiffinDB\Tests\Support\TempDir;

/**
 * M7 spec section 9.1: the file must be exactly the text start() writes,
 * so text before the first line (or a byte-order mark) is no code.
 */
#[CoversClass(RecoveryCode::class)]
final class RecoveryCodeAnchorTest extends TestCase
{
    private TempDir $temp;
    private string $data;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->data = $this->temp->path('data');
        mkdir($this->data, 0750);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    /** @return iterable<string, array{string}> */
    public static function prefixes(): iterable
    {
        yield 'a line before' => ["planted\n"];
        yield 'a byte-order mark' => ["\u{FEFF}"];
        yield 'a space' => [' '];
    }

    #[DataProvider('prefixes')]
    public function testTextBeforeTheFirstLineIsNoCode(string $prefix): void
    {
        $now = new \DateTimeImmutable('2026-03-02T10:00:00Z');
        file_put_contents($this->data . '/' . RecoveryCode::FILE, $prefix . RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', $now));

        self::assertNull(RecoveryCode::current($this->data, $now));
    }
}
