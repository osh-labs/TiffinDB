<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Identifier;

/** T-SCH-4: Identifier builds names from keys only, and refuses everything else. */
#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    private const KEY = 'h3kq7vn2xbma';

    public function testOutputsForValidKeys(): void
    {
        self::assertSame('"t_h3kq7vn2xbma"', Identifier::table(self::KEY));
        self::assertSame('"f_h3kq7vn2xbma"', Identifier::column(self::KEY));
        self::assertSame('"j_h3kq7vn2xbma"', Identifier::joinTable(self::KEY));
        self::assertSame('t_h3kq7vn2xbma', Identifier::tableName(self::KEY));
        self::assertSame('f_h3kq7vn2xbma', Identifier::columnName(self::KEY));
        self::assertSame('j_h3kq7vn2xbma', Identifier::joinName(self::KEY));
        self::assertSame('"x_t_h3kq7vn2xbma_trash"', Identifier::index('t_h3kq7vn2xbma', 'trash'));
        self::assertSame('"x_f_h3kq7vn2xbma_u"', Identifier::index('f_h3kq7vn2xbma', 'u'));
        self::assertSame('"x_j_h3kq7vn2xbma_r"', Identifier::index('j_h3kq7vn2xbma', 'r'));
        self::assertSame('"x_j_h3kq7vn2xbma_l1"', Identifier::index('j_h3kq7vn2xbma', 'l1'));
        self::assertSame('"x_j_h3kq7vn2xbma_r1"', Identifier::index('j_h3kq7vn2xbma', 'r1'));
        self::assertSame(['trash', 'u', 'r', 'l1', 'r1'], Identifier::PURPOSES);
    }

    public function testEveryKeyAlphabetCharacterIsAccepted(): void
    {
        self::assertSame('"t_abcdefghijkl"', Identifier::table('abcdefghijkl'));
        self::assertSame('"t_mnopqrstuvwx"', Identifier::table('mnopqrstuvwx'));
        self::assertSame('"t_yz2345672345"', Identifier::table('yz2345672345'));
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'upper case' => ['H3KQ7VN2XBMA'];
        yield 'one upper case letter' => ['h3kq7vn2xbmA'];
        yield 'too short' => ['h3kq7vn2xbm'];
        yield 'too long' => ['h3kq7vn2xbmaa'];
        yield 'empty' => [''];
        yield 'digit outside the alphabet' => ['h3kq7vn2xbm1'];
        yield 'zero' => ['h3kq7vn2xbm0'];
        yield 'eight' => ['h3kq7vn2xbm8'];
        yield 'double quote' => ['h3kq7vn2xb"a'];
        yield 'single quote' => ["h3kq7vn2xb'a"];
        yield 'space' => ['h3kq7vn xbma'];
        yield 'trailing newline' => ["h3kq7vn2xbma\n"];
        yield 'NUL' => ["h3kq7vn2xbm\0"];
        yield 'non-ASCII' => ['h3kq7vn2xbmä'];
        yield 'underscore' => ['h3kq7vn2_bma'];
        yield 'injection' => ['x"; DROP TABLE t; --'];
        yield 'already prefixed' => ['t_h3kq7vn2xb'];
    }

    #[DataProvider('badKeys')]
    public function testTableRefusesBadKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifier::table($key);
    }

    #[DataProvider('badKeys')]
    public function testEveryBuilderRefusesBadKeys(string $key): void
    {
        foreach (['column', 'joinTable', 'tableName', 'columnName', 'joinName'] as $method) {
            try {
                Identifier::$method($key);
                self::fail($method . ' accepted a bad key.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSystemAcceptsOnlyTheFixedNames(): void
    {
        foreach (Identifier::SYSTEM as $name) {
            self::assertSame('"' . $name . '"', Identifier::system($name));
        }
        self::assertSame(
            ['id', '_created_at', '_created_by', '_updated_at', '_updated_by', '_trashed_at', '_trashed_by', 'left_id', 'right_id', 'position'],
            Identifier::SYSTEM,
        );
        foreach (['ID', 'name', '_tiffin_meta', 'id"', 'id; DROP TABLE x', 'rowid', 'sqlite_master', '', ' id'] as $bad) {
            try {
                Identifier::system($bad);
                self::fail('system() accepted ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badIndexes(): iterable
    {
        yield 'other purpose' => ['t_h3kq7vn2xbma', 'x'];
        yield 'purpose upper case' => ['t_h3kq7vn2xbma', 'U'];
        yield 'purpose injection' => ['t_h3kq7vn2xbma', 'u"; DROP TABLE t; --'];
        yield 'empty purpose' => ['t_h3kq7vn2xbma', ''];
        yield 'quoted object name' => ['"t_h3kq7vn2xbma"', 'u'];
        yield 'bare key' => ['h3kq7vn2xbma', 'u'];
        yield 'other prefix' => ['x_h3kq7vn2xbma', 'u'];
        yield 'metadata table' => ['_tiffin_tables', 'u'];
        yield 'bad key in name' => ['t_H3KQ7VN2XBMA', 'u'];
        yield 'trailing newline' => ["t_h3kq7vn2xbma\n", 'u'];
        yield 'injection in name' => ['t_x"; DROP TABLE t; --', 'u'];
    }

    #[DataProvider('badIndexes')]
    public function testIndexRefusesOtherNamesAndPurposes(string $object, string $purpose): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifier::index($object, $purpose);
    }

    public function testEveryOutputMatchesTheNamePattern(): void
    {
        $outputs = [
            Identifier::table(self::KEY), Identifier::column(self::KEY), Identifier::joinTable(self::KEY),
            Identifier::index('t_' . self::KEY, 'trash'), Identifier::system('_trashed_by'),
        ];
        foreach ($outputs as $output) {
            self::assertMatchesRegularExpression('/^"[a-z0-9_]+"\z/', $output);
            self::assertMatchesRegularExpression(Identifier::NAME, substr($output, 1, -1));
        }
    }
}
