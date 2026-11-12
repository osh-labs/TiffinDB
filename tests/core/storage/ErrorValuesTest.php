<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\Busy;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Error\CoreException;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Error\ValidationFailed;

/** Core\Error values (M1 spec section 3.1) and the Text rules (section 3.4, P52). */
#[CoversClass(Problem::class)]
#[CoversClass(ValidationFailed::class)]
#[CoversClass(ConfirmationRequired::class)]
#[CoversClass(Unavailable::class)]
#[CoversClass(Text::class)]
final class ErrorValuesTest extends TestCase
{
    public function testExceptionHierarchy(): void
    {
        foreach ([new NotFound(), new AccessDenied(), new Busy(), new Unavailable('missing'),
            new ConfirmationRequired(2, 3), new ValidationFailed([new Problem(null, 'problem.name.empty')])] as $e) {
            self::assertInstanceOf(CoreException::class, $e);
            self::assertInstanceOf(RuntimeException::class, $e);
        }
    }

    public function testValues(): void
    {
        $problem = new Problem('name', 'problem.name.too_long', ['max' => 60]);
        self::assertSame('name', $problem->field);
        self::assertSame('problem.name.too_long', $problem->key);
        self::assertSame(['max' => 60], $problem->params);
        self::assertSame([], (new Problem(null, 'problem.throttled'))->params);

        $failed = new ValidationFailed([$problem]);
        self::assertSame([$problem], $failed->problems);

        $confirm = new ConfirmationRequired(4, 7);
        self::assertSame([4, 7], [$confirm->records, $confirm->values]);

        foreach (['missing', 'not-project', 'newer', 'mismatch'] as $reason) {
            self::assertSame($reason, (new Unavailable($reason))->reason);
        }
    }

    public function testValidationFailedNeedsProblems(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ValidationFailed([]);
    }

    public function testUnavailableNeedsAKnownReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Unavailable('gone');
    }

    public function testUtf8AndLength(): void
    {
        self::assertTrue(Text::isUtf8(''));
        self::assertTrue(Text::isUtf8('Café ✓ 𝄞'));
        self::assertFalse(Text::isUtf8("\xC3\x28"));
        self::assertFalse(Text::isUtf8("\xED\xA0\x80"), 'A UTF-16 surrogate is not UTF-8.');
        self::assertSame(0, Text::length(''));
        self::assertSame(8, Text::length('Café ✓ 𝄞'));
        self::assertSame(2, Text::length("\u{1F600}\n"), 'A line break is one code point.');
        $this->expectException(InvalidArgumentException::class);
        Text::length("\xC3\x28");
    }

    public function testTrim(): void
    {
        self::assertSame('Example', Text::trim("  \t\nExample \r\n"));
        self::assertSame('Example name', Text::trim("\u{00A0}\u{3000}Example name\u{2003}"));
        self::assertSame('in  between', Text::trim(' in  between '));
        self::assertSame("\xC3\x28 ", Text::trim("\xC3\x28 "), 'Invalid UTF-8 is returned unchanged.');
    }

    public function testControlCharacters(): void
    {
        self::assertFalse(Text::hasControl('Plain text, with punctuation!'));
        self::assertFalse(Text::hasControl("Zero\u{200D}width joiner is not Cc"));
        self::assertTrue(Text::hasControl("tab\there"));
        self::assertFalse(Text::hasControl("tab\there", allowTab: true));
        self::assertTrue(Text::hasControl("line\nbreak"));
        self::assertFalse(Text::hasControl("line\nbreak", allowNewline: true));
        self::assertTrue(Text::hasControl("line\r\nbreak", allowNewline: true), 'Carriage return is never allowed.');
        self::assertTrue(Text::hasControl("nul\x00"));
        self::assertTrue(Text::hasControl("del\x7F"));
        self::assertTrue(Text::hasControl("c1\u{0085}"));
        self::assertTrue(Text::hasControl("\xC3\x28"), 'Invalid UTF-8 counts as control.');
    }

    /** @return iterable<string, array{string, list<array{string, array<string, int>}>}> */
    public static function names(): iterable
    {
        yield 'fine' => ['Inventory', []];
        yield 'trimmed to fine' => ["  Items\u{00A0}", []];
        yield 'exactly max' => [str_repeat('é', 10), []];
        yield 'empty' => ['', [['problem.name.empty', []]]];
        yield 'blank' => [" \t ", [['problem.name.empty', []]]];
        yield 'too long' => [str_repeat('é', 11), [['problem.name.too_long', ['max' => 10]]]];
        yield 'control' => ["Ite\x01ms", [['problem.text.control', []]]];
        yield 'inner line break' => ["Two\nlines", [['problem.text.control', []]]];
        yield 'too long and control' => [str_repeat('x', 11) . "\x07", [['problem.name.too_long', ['max' => 10]], ['problem.text.control', []]]];
        yield 'invalid utf-8' => ["Bad \xC3\x28", [['problem.text.control', []]]];
    }

    /** @param list<array{string, array<string, int>}> $expected */
    #[DataProvider('names')]
    public function testNameProblems(string $value, array $expected): void
    {
        $problems = Text::nameProblems($value, 'name', 10);
        self::assertSame($expected, array_map(static fn (Problem $p): array => [$p->key, $p->params], $problems));
        foreach ($problems as $problem) {
            self::assertSame('name', $problem->field);
        }
    }

    public function testOptionalTextProblems(): void
    {
        self::assertSame([], Text::optionalTextProblems('', 'help', 5));
        self::assertSame([], Text::optionalTextProblems('   ', 'help', 5));
        self::assertSame([], Text::optionalTextProblems(' Hint ', 'help', 5));
        $long = Text::optionalTextProblems('Too long', 'help', 5);
        self::assertCount(1, $long);
        self::assertSame(['help', 'problem.text.too_long', ['max' => 5]], [$long[0]->field, $long[0]->key, $long[0]->params]);
        $control = Text::optionalTextProblems("a\tb", 'help', 5);
        self::assertSame(['problem.text.control'], array_map(static fn (Problem $p): string => $p->key, $control));
        $invalid = Text::optionalTextProblems("\xC3\x28", 'help', 5);
        self::assertSame(['problem.text.control'], array_map(static fn (Problem $p): string => $p->key, $invalid));
    }
}
