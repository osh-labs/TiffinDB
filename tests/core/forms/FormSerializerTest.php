<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\Block;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\Inline;
use TiffinDB\Core\Forms\LineBreak;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-FORM-3: parse(serialize(d)) equals d for 200 documents built from a
 * seeded random source (seed below), and serialize(parse(t)) is a fixed
 * point after one round. The comparison with the document's example is in
 * FormGeneratorTest. All text is synthetic.
 */
#[CoversClass(FormSerializer::class)]
final class FormSerializerTest extends TestCase
{
    private const SEED = 'tiffindb-form-serializer-test-1';

    /** Synthetic text pieces, including characters that need escaping and non-ASCII text. */
    private const WORDS = ['Item', 'a & b', '<tag>', '"quoted"', "it's", 'Caf' . "\u{E9}", "\u{1F600}", ' ', "\t", "\n", 'x', '&amp;', ']]>', 'Room 5'];
    private const HREFS = ['https://example.com/', 'http://example.org/a?b=1&c=2#d', 'https://192.0.2.10:8080/path'];

    private SeededRandom $random;
    private Keys $keys;

    private function int(int $max): int
    {
        return intdiv(ord($this->random->bytes(1)) * ($max + 1), 256);
    }

    private function text(): string
    {
        $text = '';
        $words = $this->int(3) + 1;
        for ($i = 0; $i < $words; $i++) {
            $text .= self::WORDS[$this->int(count(self::WORDS) - 1)];
        }

        return $text;
    }

    /** One-line text: at most four words of at most eight characters, so within every limit. */
    private function plain(): string
    {
        $text = trim(str_replace(["\t", "\n"], ' ', $this->text()));

        return $text === '' ? 'Label' : $text;
    }

    private function placement(): ?Placement
    {
        if ($this->int(2) !== 0) {
            return null;
        }
        $col = $this->int(1) === 0 ? $this->int(11) + 1 : null;
        $row = $this->int(1) === 0 ? $this->int(49) + 1 : null;
        $span = $this->int(1) === 0 ? ($this->int(1) === 0 ? 'full' : (string) ($this->int(11) + 1)) : null;

        return $col === null && $row === null && $span === null ? new Placement(null, null, 'full') : new Placement($col, $row, $span);
    }

    /** @return list<Inline> */
    private function inlines(int $depth, bool $inLink): array
    {
        $inlines = [];
        $count = $this->int(4);
        for ($i = 0; $i < $count; $i++) {
            $choice = $depth >= 3 ? $this->int(1) : $this->int(5);
            $node = match ($choice) {
                0 => new TextRun($this->text()),
                1 => new LineBreak(),
                2 => new Strong($this->inlines($depth + 1, $inLink)),
                3 => new Emphasis($this->inlines($depth + 1, $inLink)),
                default => $inLink ? new LineBreak() : new Link(self::HREFS[$this->int(2)], $this->inlines($depth + 1, true)),
            };
            if ($node instanceof TextRun && $inlines !== [] && $inlines[count($inlines) - 1] instanceof TextRun) {
                continue;
            }
            $inlines[] = $node;
        }

        return $inlines;
    }

    private function block(bool $inSection): Block
    {
        return match ($this->int($inSection ? 5 : 6)) {
            0 => new FieldSlot($this->keys->generate(), $this->int(1) === 0 ? $this->plain() : null, $this->placement()),
            1 => new Heading(2 + $this->int(1), $this->inlines(0, false), $this->placement()),
            2 => new Paragraph($this->inlines(0, false), $this->placement()),
            3 => new Divider($this->placement()),
            4 => new Image($this->int(255) + 1, $this->int(1) === 0 ? '' : $this->plain(), $this->placement()),
            5 => new FieldSlot($this->keys->generate()),
            default => $this->section(),
        };
    }

    private function section(): Section
    {
        $children = [];
        $count = $this->int(5);
        for ($i = 0; $i < $count; $i++) {
            $children[] = $this->block(true);
        }
        $role = ['details', 'files', 'links', 'my-role-1', null][$this->int(4)];

        return new Section($role, $this->int(3) === 0 ? null : $this->plain(), $children, $this->placement());
    }

    private function document(): FormDocument
    {
        $blocks = [];
        $count = $this->int(6);
        for ($i = 0; $i < $count; $i++) {
            $blocks[] = $this->block(false);
        }

        return new FormDocument($blocks);
    }

    public function testRoundTripOfSeededDocuments(): void
    {
        $this->random = new SeededRandom(self::SEED);
        $this->keys = new Keys(new SeededRandom(self::SEED . '-keys'));
        $parser = new FormParser(FieldTypes::standard());
        $serializer = new FormSerializer();
        $kinds = [];
        for ($n = 0; $n < 200; $n++) {
            $document = $this->document();
            $text = $serializer->serialize($document);
            self::assertEquals($document, $parser->parse($text), 'Document ' . $n);
            self::assertSame($text, $serializer->serialize($parser->parse($text)), 'Document ' . $n);
            self::assertStringEndsWith("</div>\n", $text);
            foreach (['<fieldset', '<legend>', '<h2', '<h3', '<p', '<hr', '<img', '<strong>', '<em>', '<br/>', '<a href', 'data-tiffin-label', 'data-tiffin-col', 'data-tiffin-row', 'data-tiffin-span="full"'] as $mark) {
                if (str_contains($text, $mark)) {
                    $kinds[$mark] = true;
                }
            }
        }
        // The seeded documents exercise every element and attribute.
        self::assertCount(15, $kinds);
    }

    public function testCanonicalText(): void
    {
        $document = new FormDocument([
            new Section('details', 'A & B', [
                new FieldSlot('m4pz7k2qsw3n', 'Say "hi"', new Placement(2, 3, 'full')),
                new Paragraph([new TextRun("<x> it's"), new LineBreak(), new Link('https://example.com/?a=1&b=2', [new Strong([new TextRun('go')])])]),
                new Image(5, '', new Placement(null, null, '6')),
                new Divider(),
            ]),
            new Heading(3, [new Emphasis([])]),
            new Section(null, null, []),
        ]);
        self::assertSame(
            '<div data-tiffin-form="1">' . "\n"
            . '  <fieldset data-tiffin-section="details">' . "\n"
            . '    <legend>A &amp; B</legend>' . "\n"
            . '    <div data-tiffin-field="m4pz7k2qsw3n" data-tiffin-label="Say &quot;hi&quot;" data-tiffin-col="2" data-tiffin-row="3" data-tiffin-span="full"></div>' . "\n"
            . '    <p>&lt;x&gt; it&apos;s<br/><a href="https://example.com/?a=1&amp;b=2"><strong>go</strong></a></p>' . "\n"
            . '    <img data-tiffin-span="6" src="attachment:5" alt=""/>' . "\n"
            . '    <hr/>' . "\n"
            . '  </fieldset>' . "\n"
            . '  <h3><em></em></h3>' . "\n"
            . '  <fieldset>' . "\n"
            . '  </fieldset>' . "\n"
            . '</div>' . "\n",
            (new FormSerializer())->serialize($document),
        );
    }

    public function testSerializeOfParseIsAFixedPoint(): void
    {
        $parser = new FormParser(FieldTypes::standard());
        $serializer = new FormSerializer();
        $loose = '<!-- hand-written --><div data-tiffin-form="1"><fieldset   data-tiffin-section="details"><legend>  Details  </legend>'
            . '<div data-tiffin-label=" Named " data-tiffin-field="m4pz7k2qsw3n"/><p>a &#38; b</p><img alt="x" src="attachment:3"></img></fieldset></div>';
        $once = $serializer->serialize($parser->parse($loose));
        self::assertNotSame($loose, $once);
        self::assertSame($once, $serializer->serialize($parser->parse($once)));
        self::assertStringContainsString('<legend>Details</legend>', $once);
        self::assertStringContainsString('<div data-tiffin-field="m4pz7k2qsw3n" data-tiffin-label="Named"></div>', $once);
        self::assertStringContainsString('<img src="attachment:3" alt="x"/>', $once);
    }
}
