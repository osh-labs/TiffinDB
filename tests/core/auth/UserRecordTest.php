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
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\UserRecord;

/** T-AUTH-10: initials; also Actor and UserRecord role helpers. */
#[CoversClass(UserRecord::class)]
#[CoversClass(Actor::class)]
final class UserRecordTest extends TestCase
{
    private static function person(string $displayName, Role $role = Role::Member): UserRecord
    {
        return new UserRecord(1, 'personaaaaaa', 'person', $displayName, $role, true, false, '2026-01-01T00:00:00Z', null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'two words' => ['Example Admin', 'EA'];
        yield 'one word' => ['Example', 'E'];
        yield 'three words' => ['Example Middle Person', 'EP'];
        yield 'extra spaces' => ['  Example    Person  ', 'EP'];
        yield 'lower case' => ['example person', 'EP'];
        yield 'non-ASCII first letters' => ["\u{e9}mile \u{f8}rsted", class_exists(\IntlChar::class) ? "\u{c9}\u{d8}" : "\u{e9}\u{f8}"];
        yield 'Greek' => ["\u{3b1}\u{3bb}\u{3c6}\u{3b1} \u{3b2}\u{3b7}\u{3c4}\u{3b1}", class_exists(\IntlChar::class) ? "\u{391}\u{392}" : "\u{3b1}\u{3b2}"];
        yield 'non-breaking space separates' => ["Example\u{a0}Person", 'EP'];
        yield 'digit first' => ['4 Example', '4E'];
    }

    #[DataProvider('names')]
    public function testInitials(string $displayName, string $expected): void
    {
        $this->assertSame($expected, self::person($displayName)->initials());
    }

    public function testRoleHelpers(): void
    {
        $this->assertTrue(self::person('Example Admin', Role::Admin)->isAdmin());
        $this->assertFalse(self::person('Example Member')->isAdmin());
        $this->assertTrue((new Actor(1, 'adminaaaaaaa', Role::Admin, 'Example Admin'))->isAdmin());
        $this->assertFalse((new Actor(2, 'memberaaaaaa', Role::Member, 'Example Member'))->isAdmin());
    }
}
