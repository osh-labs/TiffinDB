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
use TiffinDB\Core\Auth\DisplayNames;
use TiffinDB\Core\Auth\Usernames;
use TiffinDB\Core\Error\Problem;

/** T-AUTH-3: username rules and normalisation; display names. */
#[CoversClass(Usernames::class)]
#[CoversClass(DisplayNames::class)]
final class UsernamesTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function usernames(): iterable
    {
        yield 'plain' => ['admin', true];
        yield 'three characters' => ['abc', true];
        yield 'two characters' => ['ab', false];
        yield 'thirty-two characters' => [str_repeat('a', 32), true];
        yield 'thirty-three characters' => [str_repeat('a', 33), false];
        yield 'all allowed punctuation' => ['ex.ample_person-2', true];
        yield 'upper case' => ['ExampleAdmin', true];
        yield 'digits only' => ['555', true];
        yield 'trimmed before checking' => ['  admin  ', true];
        yield 'inner space' => ['example person', false];
        yield 'at sign' => ['user@example', false];
        yield 'plus' => ['a+b', false];
        yield 'non-ASCII letter' => ["j\u{f6}rg", false];
        yield 'empty' => ['', false];
        yield 'only spaces' => ['    ', false];
        yield 'tab inside' => ["ad\tmin", false];
        yield 'trailing newline' => ["admin\n", true];
        yield 'NUL byte' => ["admin\0", false];
        yield 'invalid UTF-8' => ["adm\xffin", false];
    }

    #[DataProvider('usernames')]
    public function testRules(string $username, bool $valid): void
    {
        $problems = Usernames::problems($username);
        if ($valid) {
            $this->assertSame([], $problems);

            return;
        }
        $this->assertCount(1, $problems);
        $this->assertSame('username', $problems[0]->field);
        $this->assertSame('problem.username.invalid', $problems[0]->key);
    }

    public function testNormalisationLowerCasesAsciiAndTrims(): void
    {
        $this->assertSame('example.admin', Usernames::normalise('Example.ADMIN'));
        $this->assertSame('admin', Usernames::normalise('  Admin '));
        $this->assertSame("j\u{d6}rg", Usernames::normalise("J\u{d6}rg"), 'Only ASCII letters are folded.');
    }

    public function testDisplayNamesUseTheSharedTextRules(): void
    {
        $keys = static fn (array $problems): array => array_map(static fn (Problem $p): string => $p->key, $problems);
        $this->assertSame([], DisplayNames::problems('Example Person'));
        $this->assertSame([], DisplayNames::problems(str_repeat('a', 60)));
        $this->assertSame(['problem.name.too_long'], $keys(DisplayNames::problems(str_repeat('a', 61))));
        $this->assertSame(['max' => 60], DisplayNames::problems(str_repeat('a', 61))[0]->params);
        $this->assertSame(['problem.name.empty'], $keys(DisplayNames::problems('   ')));
        $this->assertSame(['problem.text.control'], $keys(DisplayNames::problems("Example\tPerson")));
        $this->assertSame('display_name', DisplayNames::problems('')[0]->field);
        $this->assertSame('name', DisplayNames::problems('', 'name')[0]->field);
    }
}
