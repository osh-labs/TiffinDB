<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Error\Problem;

/** T-AUTH-1: password rules. */
#[CoversClass(PasswordPolicy::class)]
final class PasswordPolicyTest extends TestCase
{
    /** @param list<Problem> $problems @return list<string> */
    private static function keys(array $problems): array
    {
        return array_map(static fn (Problem $p): string => $p->key, $problems);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function cases(): iterable
    {
        yield '9 characters' => ['abcdefghi', 'admin', ['problem.password.too_short']];
        yield '10 characters' => ['abcdefghij', 'admin', []];
        yield 'empty' => ['', 'admin', ['problem.password.too_short']];
        yield '72 bytes' => [str_repeat('a', 72), 'admin', []];
        yield '73 bytes' => [str_repeat('a', 73), 'admin', ['problem.password.too_long']];
        // 'é' is 2 bytes: 36 of them are 72 bytes, 37 are 74.
        yield '72 bytes multi-byte' => [str_repeat("\u{e9}", 36), 'admin', []];
        yield '73 bytes multi-byte' => [str_repeat("\u{e9}", 36) . 'a', 'admin', ['problem.password.too_long']];
        // 10 characters of 3 bytes each pass the minimum though they are 30 bytes; 9 of them do not.
        yield '10 multi-byte characters' => [str_repeat("\u{20ac}", 10), 'admin', []];
        yield '9 multi-byte characters' => [str_repeat("\u{20ac}", 9), 'admin', ['problem.password.too_short']];
        yield 'same as username' => ['exampleperson', 'exampleperson', ['problem.password.same_as_username']];
        yield 'same as username ignoring case' => ['ExamplePerson', 'examplepERSON', ['problem.password.same_as_username']];
        yield 'username with spaces around it' => ['exampleperson', '  exampleperson ', ['problem.password.same_as_username']];
        yield 'contains the username' => ['exampleperson1', 'exampleperson', []];
        yield 'not trimmed' => ['  exampleperson', 'exampleperson', []];
        yield 'invalid UTF-8' => ["abcdefghij\xff", 'admin', ['problem.text.control']];
        yield 'NUL byte' => ["abcdefghij\0", 'admin', ['problem.text.control']];
    }

    /** @param list<string> $expected */
    #[DataProvider('cases')]
    public function testRules(string $password, string $username, array $expected): void
    {
        $this->assertSame($expected, self::keys(PasswordPolicy::problems($password, $username)));
    }

    public function testFieldAndParameters(): void
    {
        $problems = PasswordPolicy::problems('short', 'admin', 'temporary_password');
        $this->assertCount(1, $problems);
        $this->assertSame('temporary_password', $problems[0]->field);
        $this->assertSame(['min' => 10], $problems[0]->params);
        $this->assertSame('password', PasswordPolicy::problems('short', 'admin')[0]->field);
    }
}
