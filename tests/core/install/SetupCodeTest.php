<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Install\SetupCode;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Tests\Support\SeededRandom;

/** Setup codes (section 10.5), used by T-INS-3 and T-INS-7. */
#[CoversClass(SetupCode::class)]
final class SetupCodeTest extends TestCase
{
    public function testGeneratedCodesHaveTheFormAndAreDeterministic(): void
    {
        $a = SetupCode::generate(new SeededRandom('tiffindb-setup-code-seed-1'));
        $b = SetupCode::generate(new SeededRandom('tiffindb-setup-code-seed-1'));
        $c = SetupCode::generate(new SeededRandom('tiffindb-setup-code-seed-2'));

        $this->assertMatchesRegularExpression(SetupCode::PATTERN, $a);
        $this->assertSame(19, strlen($a));
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testBiasedBytesAreRejected(): void
    {
        // Bytes of 240 or more are skipped; 0 gives 'A', 29 gives '9', 30 gives 'A' again.
        $random = new class implements Random {
            public function bytes(int $length): string
            {
                return substr(str_repeat("\xf0\xff\x00\x1d\x1e", 8), 0, $length);
            }
        };

        // Each 16-byte draw yields A 9 A three times (9 characters); two draws fill 16.
        $this->assertSame('A9AA-9AA9-AA9A-A9AA', SetupCode::generate($random));
    }

    public function testMatchingComparesInConstantTime(): void
    {
        // Static check: a plain === or strcmp would give the same results above
        // but leak timing (review nit; such a mutation survived the behaviour tests).
        $method = new \ReflectionMethod(SetupCode::class, 'matches');
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertSame(1, preg_match_all('/\bhash_equals\(\s*\$expected\s*,\s*\$given\s*\)/', $body));
        $this->assertDoesNotMatchRegularExpression('/\$expected\s*[!=]==?\s*\$given|\$given\s*[!=]==?\s*\$expected|strcmp|strcasecmp/', $body);
    }

    public function testMatching(): void
    {
        $file = "ABCD-EFGH-JKMN-PQRS\n";

        $this->assertTrue(SetupCode::matches($file, 'ABCD-EFGH-JKMN-PQRS'));
        $this->assertTrue(SetupCode::matches($file, ' abcd efgh jkmn pqrs '));
        $this->assertTrue(SetupCode::matches($file, "abcdefgh\tjkmnpqrs"));
        $this->assertFalse(SetupCode::matches($file, 'ABCD-EFGH-JKMN-PQRT'));
        $this->assertFalse(SetupCode::matches($file, 'ABCD-EFGH-JKMN'));
        $this->assertFalse(SetupCode::matches($file, ''));
        $this->assertFalse(SetupCode::matches($file, ' - '));
        $this->assertFalse(SetupCode::matches('', ''));
        $this->assertFalse(SetupCode::matches("\n", '-'));
        $this->assertFalse(SetupCode::matches($file, "ABCD-EFGH-JKMN-PQRS\xff"));
    }
}
