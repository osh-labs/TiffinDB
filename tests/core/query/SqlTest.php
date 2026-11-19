<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Query\Sql;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Storage\Sqlite;

/** T-REC-14 (run-time part): the checks Sql makes on every fragment, with hostile text and values. */
#[CoversClass(Sql::class)]
final class SqlTest extends TestCase
{
    public function testFragmentsCarryTextAndValuesInOrder(): void
    {
        $a = Sql::of('"x" = ?', [1]);
        $b = Sql::of('"y" IN (?, ?)', ['two', null]);
        $c = Sql::of('"z" IS NULL');
        self::assertSame('"x" = ?', $a->sql());
        self::assertSame([1], $a->params());
        $and = Sql::join(' AND ', [$a, $b, $c]);
        self::assertSame('"x" = ? AND "y" IN (?, ?) AND "z" IS NULL', $and->sql());
        self::assertSame([1, 'two', null], $and->params());
        self::assertSame('("x" = ? AND "y" IN (?, ?) AND "z" IS NULL)', $and->wrap()->sql());
        self::assertSame([1, 'two', null], $and->wrap()->params());
        self::assertSame('"x" = ? OR "z" IS NULL', Sql::join(' OR ', [$a, $c])->sql());
        self::assertSame('"x" = ?, "z" IS NULL', Sql::join(', ', [$a, $c])->sql());
        self::assertSame('"z" IS NULL "x" = ?', Sql::join(' ', [$c, $a])->sql());
        self::assertSame([1], Sql::join(' ', [$c, $a])->params());
        self::assertSame([1.5, true, false, -7, ''], Sql::of('?, ?, ?, ?, ?', [1.5, true, false, -7, ''])->params());
        // Fragments are values: joining never changes the parts.
        self::assertSame('"x" = ?', $a->sql());
    }

    /** @return iterable<string, array{string, array<mixed>}> */
    public static function refusedFragments(): iterable
    {
        yield 'more placeholders than values' => ['"a" = ? AND "b" = ?', [1]];
        yield 'more values than placeholders' => ['"a" = ?', [1, 2]];
        yield 'a placeholder and no values' => ['"a" = ?', []];
        yield 'values and no placeholder' => ['"a" = 1', ['x']];
        yield 'a question mark hidden in a quoted literal' => ['"a" = \'?\'', []];
        yield 'empty text' => ['', []];
        yield 'statement separator' => ['"a" = ?; DROP TABLE "t"', [1]];
        yield 'line comment' => ['"a" = ? -- rest', [1]];
        yield 'block comment' => ['"a" = ? /* rest', [1]];
        yield 'NUL' => ["\"a\" = ?\0", [1]];
        yield 'numbered placeholder' => ['"a" = ?1', [1]];
        yield 'named placeholder' => ['"a" = :value', []];
        yield 'at placeholder' => ['"a" = @value', []];
        yield 'dollar placeholder' => ['"a" = $value', []];
        yield 'named values' => ['"a" = ?', ['v' => 1]];
        yield 'list with a gap' => ['"a" = ?', [1 => 1]];
        yield 'array value' => ['"a" = ?', [[1]]];
        yield 'object value' => ['"a" = ?', [new \stdClass()]];
        yield 'NAN value' => ['"a" = ?', [NAN]];
        yield 'INF value' => ['"a" = ?', [INF]];
    }

    /** @param array<mixed> $params */
    #[DataProvider('refusedFragments')]
    public function testOfRefuses(string $text, array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sql::of($text, $params);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedGlues(): iterable
    {
        foreach (['', 'AND', ' and ', ' AND', ' UNION ', '; ', ' ; ', ',', ' -- ', "\n", ' AND NOT ', ' OR 1=1 OR '] as $glue) {
            yield json_encode($glue, JSON_THROW_ON_ERROR) => [$glue];
        }
    }

    #[DataProvider('refusedGlues')]
    public function testJoinRefusesOtherGlue(string $glue): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sql::join($glue, [Sql::of('1'), Sql::of('2')]);
    }

    public function testJoinRefusesAnEmptyOrForeignList(): void
    {
        foreach ([[], ['"a" = 1'], [Sql::of('1'), null], [1 => Sql::of('1')]] as $parts) {
            try {
                Sql::join(' AND ', $parts);
                self::fail(json_encode($parts) ?: '');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHostileValuesAreBoundNotWritten(): void
    {
        $db = Sqlite::memory();
        $db->run('CREATE TABLE "t" ("v" TEXT)');
        $values = ["'); DROP TABLE \"t\"; --", '"', '?', '%_\\', "x' OR '1'='1", 'é'];
        foreach ($values as $value) {
            $insert = Sql::join(' ', [Sql::of('INSERT INTO "t" ("v")'), Sql::of('VALUES (?)', [$value])]);
            $db->run($insert->sql(), $insert->params());
        }
        $select = Sql::join(' ', [Sql::of('SELECT "v" FROM "t" WHERE'), Sql::join(' OR ', [Sql::of('"v" = ?', [$values[0]]), Sql::of('"v" = ?', [$values[4]])])]);
        self::assertSame([$values[0], $values[4]], array_column($db->all($select->sql(), $select->params()), 'v'));
        self::assertSame($values, array_column($db->all('SELECT "v" FROM "t" ORDER BY rowid'), 'v'));
        $db->close();
    }

    public function testHostileIdentifiersNeverBecomeFragments(): void
    {
        foreach (['"; DROP TABLE x; --', 'ABCDEFGHIJKL', 'aaaaaaaaaaa"', '', 'aaaaaaaaaaaa; '] as $key) {
            try {
                Sql::of('SELECT ' . Identifier::column($key) . ' FROM t');
                self::fail($key);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('SELECT "f_aaaaaaaaaaaa" FROM "t_bbbbbbbbbbbb"', Sql::of('SELECT ' . Identifier::column('aaaaaaaaaaaa') . ' FROM ' . Identifier::table('bbbbbbbbbbbb'))->sql());
    }

    public function testOnlyFactoriesBuildFragments(): void
    {
        $class = new \ReflectionClass(Sql::class);
        self::assertTrue($class->isFinal());
        self::assertTrue($class->getConstructor()?->isPrivate());
        $public = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $class->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($public);
        self::assertSame(['join', 'of', 'params', 'sql', 'wrap'], $public);
        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isPrivate() && $property->isReadOnly(), $property->getName());
        }
    }
}
