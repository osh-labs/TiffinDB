<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Csrf;
use TiffinDB\Tests\Support\SeededRandom;

/** T-AUTH-8: CSRF tokens. */
#[CoversClass(Csrf::class)]
final class CsrfTest extends TestCase
{
    public function testGenerateGives64LowerCaseHexCharacters(): void
    {
        $a = Csrf::generate(new SeededRandom('tiffindb-csrf-test-seed-1'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $a);
        $this->assertSame($a, Csrf::generate(new SeededRandom('tiffindb-csrf-test-seed-1')));
        $this->assertNotSame($a, Csrf::generate(new SeededRandom('tiffindb-csrf-test-seed-2')));
    }

    public function testMatches(): void
    {
        $expected = Csrf::generate(new SeededRandom('tiffindb-csrf-test-seed-1'));
        $other = Csrf::generate(new SeededRandom('tiffindb-csrf-test-seed-2'));

        $this->assertTrue(Csrf::matches($expected, $expected), 'equal');
        $this->assertFalse(Csrf::matches($expected, $other), 'different');
        $this->assertFalse(Csrf::matches($expected, substr($expected, 0, 63)), 'too short');
        $this->assertFalse(Csrf::matches($expected, $expected . '0'), 'too long');
        $this->assertFalse(Csrf::matches($expected, ''), 'empty');
        $this->assertFalse(Csrf::matches($expected, null), 'null');
        $this->assertFalse(Csrf::matches($expected, [$expected]), 'array');
        $this->assertFalse(Csrf::matches($expected, 12345), 'integer');
        $this->assertFalse(Csrf::matches($expected, strtoupper($expected)), 'case matters');
    }

    public function testAMalformedExpectedValueNeverMatches(): void
    {
        $this->assertFalse(Csrf::matches('', ''));
        $bad = str_repeat('z', 64);
        $this->assertFalse(Csrf::matches($bad, $bad));
    }
}
