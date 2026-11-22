<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormRules;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\LineBreak;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;

/** T-FORM-1: node constructors refuse what form format 1 does not allow. */
#[CoversClass(Placement::class)]
#[CoversClass(Section::class)]
#[CoversClass(FieldSlot::class)]
#[CoversClass(Heading::class)]
#[CoversClass(Paragraph::class)]
#[CoversClass(Image::class)]
#[CoversClass(TextRun::class)]
#[CoversClass(Link::class)]
#[CoversClass(FormDocument::class)]
#[CoversClass(FormRules::class)]
final class FormNodesTest extends TestCase
{
    private const KEY = 'm4pz7k2qsw3n';
    private const OTHER = 'w2qn6kz7tb4x';

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalid(): iterable
    {
        yield 'placement with nothing' => [static fn () => new Placement()];
        yield 'column 0' => [static fn () => new Placement(0)];
        yield 'column 13' => [static fn () => new Placement(13)];
        yield 'row 0' => [static fn () => new Placement(null, 0)];
        yield 'row 51' => [static fn () => new Placement(null, 51)];
        yield 'span 0' => [static fn () => new Placement(null, null, '0')];
        yield 'span 13' => [static fn () => new Placement(null, null, '13')];
        yield 'span leading zero' => [static fn () => new Placement(null, null, '04')];
        yield 'span with sign' => [static fn () => new Placement(null, null, '+4')];
        yield 'span Full' => [static fn () => new Placement(null, null, 'Full')];

        yield 'field key upper case' => [static fn () => new FieldSlot('M4PZ7K2QSW3N')];
        yield 'field key short' => [static fn () => new FieldSlot('m4pz7k2qsw3')];
        yield 'field key with quote' => [static fn () => new FieldSlot('m4pz7k2qsw3"')];
        yield 'label empty' => [static fn () => new FieldSlot(self::KEY, '')];
        yield 'label 61 characters' => [static fn () => new FieldSlot(self::KEY, str_repeat('a', 61))];
        yield 'label untrimmed' => [static fn () => new FieldSlot(self::KEY, ' Label')];
        yield 'label with newline' => [static fn () => new FieldSlot(self::KEY, "La\nbel")];
        yield 'label with bidi override' => [static fn () => new FieldSlot(self::KEY, "La\u{202E}bel")];
        yield 'label invalid utf-8' => [static fn () => new FieldSlot(self::KEY, "La\xC3bel")];

        yield 'legend empty' => [static fn () => new Section('details', '', [])];
        yield 'legend 101 characters' => [static fn () => new Section('details', str_repeat('e', 101), [])];
        yield 'legend with tab' => [static fn () => new Section('details', "De\ttails", [])];
        yield 'legend untrimmed' => [static fn () => new Section('details', 'Details ', [])];
        yield 'role upper case' => [static fn () => new Section('Details', null, [])];
        yield 'role starting with digit' => [static fn () => new Section('1details', null, [])];
        yield 'role 33 characters' => [static fn () => new Section('a' . str_repeat('b', 32), null, [])];
        yield 'role with underscore' => [static fn () => new Section('my_role', null, [])];
        yield 'nested section' => [static fn () => new Section(null, null, [new Section(null, null, [])])];
        yield 'section holding text' => [static fn () => new Section(null, null, [new TextRun('x')])];
        yield 'section children not a list' => [static fn () => new Section(null, null, ['a' => new Divider()])];

        yield 'heading level 1' => [static fn () => new Heading(1, [])];
        yield 'heading level 4' => [static fn () => new Heading(4, [])];
        yield 'heading holding a block' => [static fn () => new Heading(2, [new Divider()])];
        yield 'adjacent text runs' => [static fn () => new Paragraph([new TextRun('a'), new TextRun('b')])];
        yield 'empty text run' => [static fn () => new TextRun('')];
        yield 'text run with carriage return' => [static fn () => new TextRun("a\rb")];
        yield 'text run with NUL' => [static fn () => new TextRun("a\0b")];
        yield 'text run with U+FFFF' => [static fn () => new TextRun("a\u{FFFF}b")];

        yield 'image id 0' => [static fn () => new Image(0, '')];
        yield 'alt 201 characters' => [static fn () => new Image(1, str_repeat('a', 201))];
        yield 'alt untrimmed' => [static fn () => new Image(1, ' a')];

        yield 'link javascript' => [static fn () => new Link('javascript:alert(1)', [])];
        yield 'link scheme-relative' => [static fn () => new Link('//example.com/', [])];
        yield 'link with space around' => [static fn () => new Link(' https://example.com/', [])];
        yield 'link with tab' => [static fn () => new Link("https://exa\tmple.com/", [])];
        yield 'link mailto' => [static fn () => new Link('mailto:user@example.com', [])];
        yield 'link inside link' => [static fn () => new Link('https://example.com/', [new Link('https://example.org/', [])])];
        yield 'link deep inside link' => [static fn () => new Link('https://example.com/', [new Strong([new Emphasis([new Link('https://example.org/', [])])])])];

        yield 'duplicate slot' => [static fn () => new FormDocument([new FieldSlot(self::KEY), new Section(null, null, [new FieldSlot(self::KEY)])])];
        yield 'document holding inline' => [static fn () => new FormDocument([new TextRun('x')])];
        yield 'too deep' => [static fn () => new FormDocument([new Section(null, null, [
            new Paragraph([new Strong([new Emphasis([new Strong([new Emphasis([new Strong([new LineBreak()])])])])])]),
        ])])];
        yield 'too many elements' => [static fn () => new FormDocument(array_fill(0, 5000, new Divider()))];
    }

    /** @param Closure(): mixed $build */
    #[DataProvider('invalid')]
    public function testConstructorsRefuse(Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }

    public function testConstructorsAcceptTheLimits(): void
    {
        $max = new Placement(12, 50, 'full');
        self::assertTrue($max->isFull());
        self::assertFalse((new Placement(1, 1, '12'))->isFull());
        self::assertSame('section', (new Section('a' . str_repeat('b', 31), str_repeat('e', 100), [new Divider()], $max))->kind());
        self::assertSame(str_repeat('l', 60), (new FieldSlot(self::KEY, str_repeat('l', 60)))->label);
        self::assertSame('', (new Image(PHP_INT_MAX, ''))->alt);
        self::assertSame("tab\tand\nnewline", (new TextRun("tab\tand\nnewline"))->text);
        self::assertSame('link', (new Link('https://example.com/a?b=c#d', [new TextRun('x'), new LineBreak(), new TextRun('y')]))->kind());

        // Depth 8: root, section, paragraph, then five inline levels.
        $deep = new Paragraph([new Strong([new Emphasis([new Strong([new Emphasis([new LineBreak()])])])])]);
        self::assertCount(1, (new FormDocument([new Section(null, null, [$deep])]))->blocks);
        // 5,000 elements: the root and 4,999 dividers.
        self::assertCount(4999, (new FormDocument(array_fill(0, 4999, new Divider())))->blocks);
        self::assertSame([self::KEY, self::OTHER], (new FormDocument([
            new FieldSlot(self::KEY),
            new Section(null, 'Legend', [new FieldSlot(self::OTHER)]),
        ]))->fieldKeys());
    }

    public function testKinds(): void
    {
        self::assertSame(
            ['section', 'field', 'heading', 'paragraph', 'divider', 'image', 'text', 'strong', 'em', 'br', 'link'],
            [
                (new Section(null, null, []))->kind(),
                (new FieldSlot(self::KEY))->kind(),
                (new Heading(3, []))->kind(),
                (new Paragraph([]))->kind(),
                (new Divider())->kind(),
                (new Image(7, 'Photo'))->kind(),
                (new TextRun('x'))->kind(),
                (new Strong([]))->kind(),
                (new Emphasis([]))->kind(),
                (new LineBreak())->kind(),
                (new Link('http://example.com/', []))->kind(),
            ],
        );
        $placement = new Placement(null, null, 'full');
        self::assertSame($placement, (new FieldSlot(self::KEY, null, $placement))->placement());
        self::assertNull((new Divider())->placement());
    }

    /** The helper methods the form editor uses (M5-WP2): same object when unchanged, every other value kept, rules still checked. */
    public function testHelperMethods(): void
    {
        $placed = new Placement(3, 2, '6');
        $slot = new FieldSlot(self::KEY, 'Label', $placed);
        self::assertSame($slot, $slot->withLabel('Label'));
        self::assertSame($slot, $slot->withSpan('6'));
        $relabelled = $slot->withLabel(null);
        self::assertEquals(new FieldSlot(self::KEY, null, $placed), $relabelled);
        self::assertSame($placed, $relabelled->placement);
        self::assertEquals(new FieldSlot(self::KEY, 'Label', new Placement(3, 2, 'full')), $slot->withSpan('full'));
        self::assertEquals(new FieldSlot(self::KEY, 'Label', new Placement(3, 2)), $slot->withSpan(null));
        self::assertEquals(new FieldSlot(self::KEY, null, new Placement(null, null, '4')), (new FieldSlot(self::KEY))->withSpan('4'));
        self::assertNull((new FieldSlot(self::KEY, null, new Placement(null, null, '4')))->withSpan(null)->placement);
        self::assertTrue($placed->isPlaced());
        self::assertTrue((new Placement(null, 50))->isPlaced());
        self::assertFalse((new Placement(null, null, 'full'))->isPlaced());
        self::assertNull(Placement::withSpanOf(null, null));
        self::assertEquals(new Placement(null, 4, '8'), Placement::withSpanOf(new Placement(null, 4, 'full'), '8'));

        $child = new Divider();
        $section = new Section('details', 'Main', [$child], $placed);
        self::assertSame($section, $section->withLegend('Main'));
        $renamed = $section->withLegend(null);
        self::assertEquals(new Section('details', null, [$child], $placed), $renamed);
        self::assertSame($child, $renamed->children[0]);
        $other = new FieldSlot(self::OTHER);
        $regrouped = $section->withChildren([$other, $child]);
        self::assertEquals(new Section('details', 'Main', [$other, $child], $placed), $regrouped);
        self::assertSame($placed, $regrouped->placement);

        foreach ([
            static fn () => $slot->withLabel(str_repeat('x', 61)),
            static fn () => $slot->withSpan('13'),
            static fn () => $section->withLegend(' Main'),
            static fn () => $section->withChildren([$section]),
        ] as $n => $broken) {
            try {
                $broken();
                self::fail('Helper ' . $n . ' accepted a value the rules refuse.');
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
