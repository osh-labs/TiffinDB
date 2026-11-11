<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Web\Strings;
use UnexpectedValueException;

/** T-WEB-4 (strings): lookup, placeholders, missing keys. */
#[CoversClass(Strings::class)]
final class StringsTest extends TestCase
{
    private static function strings(): Strings
    {
        return new Strings(__DIR__ . '/fixtures/strings/en.php');
    }

    public function testLookup(): void
    {
        self::assertSame('Page not found', self::strings()->get('error.404.title'));
        self::assertTrue(self::strings()->has('app.name'));
        self::assertFalse(self::strings()->has('no.such.key'));
    }

    public function testPlaceholdersAreReplaced(): void
    {
        self::assertSame('Records - TiffinDB', self::strings()->get('shell.title', ['title' => 'Records']));
        self::assertSame('1 and 2.5', self::strings()->get('test.two', ['a' => 1, 'b' => 2.5]));
    }

    public function testValuesAreNotRescannedForPlaceholders(): void
    {
        self::assertSame('{b} and x', self::strings()->get('test.two', ['a' => '{b}', 'b' => 'x']));
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::strings()->get('no.such.key');
    }

    public function testMissingParameterThrows(): void
    {
        $this->expectException(OutOfBoundsException::class);
        self::strings()->get('test.two', ['a' => 'x']);
    }

    public function testNonScalarParameterThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::strings()->get('shell.title', ['title' => ['x']]);
    }

    public function testFormatEscapesTextAndValues(): void
    {
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        self::assertSame(
            '&lt;b&gt;&lt;i&gt;&amp;&quot;&apos;&lt;/b&gt;',
            self::strings()->format('test.markup', ['x' => '<i>&"\''], $escape),
        );
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new Strings(__DIR__ . '/fixtures/strings/none.php'))->get('app.name');
    }

    public function testFileIsLoadedLazily(): void
    {
        // Constructing with a missing file does not fail until first use.
        $strings = new Strings(__DIR__ . '/fixtures/strings/none.php');
        self::assertInstanceOf(Strings::class, $strings);
    }
}
