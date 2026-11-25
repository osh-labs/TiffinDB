<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Forms\Block;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\EditorAction;
use TiffinDB\Core\Forms\EditorApplied;
use TiffinDB\Core\Forms\EditorPath;
use TiffinDB\Core\Forms\EditorRow;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormEditor;
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
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-EDT-1 to T-EDT-6: the editor model of M5 spec section 6. The seeded
 * forms of T-EDT-2 come from a generator in this file (seed 20261125, 200
 * forms), in the manner of FormSerializerTest. Synthetic keys and text only.
 */
#[CoversClass(FormEditor::class)]
#[CoversClass(EditorAction::class)]
#[CoversClass(EditorPath::class)]
#[CoversClass(EditorRow::class)]
#[CoversClass(EditorApplied::class)]
final class FormEditorTest extends TestCase
{
    private const SEED = '20261125';
    private const TABLE = 'tbl2tbl2tbl2';
    /** Field keys of the table; slots may also name the keys of MISSING. */
    private const KEYS = ['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc', 'dddddddddddd', 'eeeeeeeeeeee', 'ffffffffffff', 'gggggggggggg'];
    private const MISSING = ['xxxxxxxxxxxx', 'yyyyyyyyyyyy', 'zzzzzzzzzzzz'];
    private const WORDS = ['Item', 'a & b', '<tag>', '"quoted"', "it's", 'Caf' . "\u{E9}", ' ', "\t", "\n", 'x', 'Room 5'];
    private const HREF = 'https://example.com/';

    private FormEditor $editor;
    private SeededRandom $random;

    protected function setUp(): void
    {
        $this->editor = new FormEditor();
    }

    /** @return list<FieldDef> */
    private static function fields(): array
    {
        $fields = [];
        foreach (self::KEYS as $i => $key) {
            $fields[] = new FieldDef($key, self::TABLE, 'Field ' . ($i + 1), 'text', [], $i === 1, '', $i + 1, true);
        }

        return $fields;
    }

    private static function text(string $text): Paragraph
    {
        return new Paragraph([new TextRun($text)]);
    }

    /** @return list<string> */
    private static function paths(FormDocument $document): array
    {
        return array_map(static fn (EditorRow $row): string => $row->path, (new FormEditor())->rows($document, self::fields()));
    }

    private function act(FormDocument $document, string $do): FormDocument
    {
        $action = EditorAction::parse($do);
        self::assertNotNull($action, $do);

        return $this->editor->act($document, $action, self::fields());
    }

    // ------------------------------------------------------------ T-EDT-1

    public function testRowsOfAFormWithEveryBlockKind(): void
    {
        [$a, $b, $c] = self::KEYS;
        $long = str_repeat('word ', 30);
        $heading = new Heading(2, [new TextRun('Where'), new LineBreak(), new Strong([new TextRun("it\tis")]), new Link(self::HREF, [new Emphasis([new TextRun(' now ')])])]);
        $document = new FormDocument([
            $heading,
            new Section('details', 'Main', [
                new FieldSlot($a, 'Title'),
                new FieldSlot(self::MISSING[0], 'Gone'),
                new FieldSlot($b, null, new Placement(null, null, '8')),
                new FieldSlot($c, null, new Placement(2, null, '5')),
            ], new Placement(null, null, 'full')),
            new Section(null, null, []),
            self::text($long),
            new Divider(new Placement(null, 3)),
            new Image(42, 'A synthetic picture'),
        ]);

        $rows = $this->editor->rows($document, self::fields());

        self::assertSame(['1', '2', '2-1', '2-2', '2-3', '2-4', '3', '4', '5', '6'], array_map(static fn (EditorRow $r): string => $r->path, $rows));
        self::assertSame(
            ['heading', 'section', 'field', 'missing', 'field', 'field', 'section', 'paragraph', 'divider', 'image'],
            array_map(static fn (EditorRow $r): string => $r->kind, $rows),
        );
        self::assertSame([0, 0, 1, 1, 1, 1, 0, 0, 0, 0], array_map(static fn (EditorRow $r): int => $r->depth, $rows));
        self::assertSame(
            ['auto', 'full', 'auto', 'auto', 'two-thirds', 'other', 'auto', 'auto', 'auto', 'auto'],
            array_map(static fn (EditorRow $r): string => $r->width, $rows),
        );
        self::assertSame([null, null, null, null, null, '5', null, null, null, null], array_map(static fn (EditorRow $r): ?string => $r->span, $rows));
        self::assertSame([false, false, false, false, false, true, false, false, true, false], array_map(static fn (EditorRow $r): bool => $r->placed, $rows));

        self::assertSame('Where it is now', $rows[0]->summary);
        self::assertSame('Main', $rows[1]->legend);
        self::assertSame(4, $rows[1]->children);
        self::assertSame(0, $rows[6]->children);
        self::assertNull($rows[6]->legend);
        self::assertEquals(self::fields()[0], $rows[2]->field);
        self::assertSame([$a, 'Title'], [$rows[2]->fieldKey, $rows[2]->label]);
        self::assertSame([null, self::MISSING[0], null], [$rows[3]->field, $rows[3]->fieldKey, $rows[3]->label]);
        self::assertSame(80, mb_strlen($rows[7]->summary));
        self::assertSame(str_repeat('word ', 15) . "word\u{2026}", $rows[7]->summary);
        self::assertSame('', $rows[8]->summary);
        self::assertSame(['A synthetic picture', 42], [$rows[9]->summary, $rows[9]->attachmentId]);
        self::assertNull($rows[0]->attachmentId);

        self::assertSame([false, true, true, true, true, true, true, true, true, true], array_map(static fn (EditorRow $r): bool => $r->canMoveUp, $rows));
        self::assertSame([true, true, true, true, true, true, true, true, true, false], array_map(static fn (EditorRow $r): bool => $r->canMoveDown, $rows));
    }

    public function testRowsOfTheGeneratedShapeAndOfAnEmptyForm(): void
    {
        self::assertSame([], $this->editor->rows(new FormDocument([]), self::fields()));
        $document = new FormDocument([new Section('details', 'Details', [new FieldSlot(self::KEYS[0]), new FieldSlot(self::KEYS[3], null, new Placement(null, null, 'full'))])]);
        $rows = $this->editor->rows($document, self::fields());
        self::assertSame(['1', '1-1', '1-2'], array_map(static fn (EditorRow $r): string => $r->path, $rows));
        self::assertSame([false, true, true], array_map(static fn (EditorRow $r): bool => $r->canMoveUp, $rows));
        self::assertSame([false, true, true], array_map(static fn (EditorRow $r): bool => $r->canMoveDown, $rows));
        self::assertSame(['auto', 'auto', 'full'], array_map(static fn (EditorRow $r): string => $r->width, $rows));
        self::assertEquals(self::fields()[3], $rows[2]->field);
        self::assertSame(2, $rows[0]->children);
    }

    public function testSummaryIsCutToEightyCharacters(): void
    {
        $exact = str_repeat('a', 80);
        $longer = str_repeat("\u{E9}", 81);
        $rows = $this->editor->rows(new FormDocument([self::text($exact), self::text($longer), self::text("  two\n\nlines\t ")]), self::fields());
        self::assertSame($exact, $rows[0]->summary);
        self::assertSame(str_repeat("\u{E9}", 79) . "\u{2026}", $rows[1]->summary);
        self::assertSame('two lines', $rows[2]->summary);
    }

    // ------------------------------------------------------------ T-EDT-2

    private function int(int $max): int
    {
        return intdiv(ord($this->random->bytes(1)) * ($max + 1), 256);
    }

    private function word(): string
    {
        $text = '';
        $words = $this->int(2) + 1;
        for ($i = 0; $i < $words; $i++) {
            $text = $text . self::WORDS[$this->int(count(self::WORDS) - 1)];
        }

        return $text;
    }

    private function plain(): string
    {
        $text = trim(str_replace(["\t", "\n"], ' ', $this->word()));

        return $text === '' ? 'Label' : $text;
    }

    private function placement(): ?Placement
    {
        return match ($this->int(5)) {
            0 => new Placement(null, null, ['full', '8', '6', '4', '5'][$this->int(4)]),
            1 => new Placement($this->int(11) + 1, $this->int(1) === 0 ? $this->int(49) + 1 : null, $this->int(1) === 0 ? '6' : null),
            default => null,
        };
    }

    /** @return list<Inline> */
    private function inlines(int $depth, bool $inLink): array
    {
        $inlines = [];
        $count = $this->int(3);
        for ($i = 0; $i < $count; $i++) {
            $node = match ($depth >= 2 ? $this->int(1) : $this->int(4)) {
                0 => new TextRun($this->word()),
                1 => new LineBreak(),
                2 => new Strong($this->inlines($depth + 1, $inLink)),
                3 => new Emphasis($this->inlines($depth + 1, $inLink)),
                default => $inLink ? new LineBreak() : new Link(self::HREF, $this->inlines($depth + 1, true)),
            };
            if ($node instanceof TextRun && $inlines !== [] && $inlines[count($inlines) - 1] instanceof TextRun) {
                continue;
            }
            $inlines[] = $node;
        }

        return $inlines;
    }

    /** @param list<string> $keys keys still free for slots */
    private function block(bool $inSection, array &$keys): Block
    {
        $choice = $this->int($inSection ? 5 : 6);
        if ($choice <= 1 && $keys !== []) {
            $key = array_splice($keys, $this->int(count($keys) - 1), 1)[0];

            return new FieldSlot($key, $this->int(2) === 0 ? $this->plain() : null, $this->placement());
        }

        return match ($choice) {
            2 => new Heading(2 + $this->int(1), $this->inlines(0, false), $this->placement()),
            3 => new Paragraph($this->inlines(0, false), $this->placement()),
            4 => new Divider($this->placement()),
            6 => $this->section($keys),
            default => new Image($this->int(30) + 1, $this->int(1) === 0 ? '' : $this->plain(), $this->placement()),
        };
    }

    /** @param list<string> $keys */
    private function section(array &$keys): Section
    {
        $children = [];
        $count = $this->int(4);
        for ($i = 0; $i < $count; $i++) {
            $children[] = $this->block(true, $keys);
        }

        return new Section([null, 'details', 'links'][$this->int(2)], $this->int(3) === 0 ? null : $this->plain(), $children, $this->placement());
    }

    private function document(): FormDocument
    {
        $keys = [...self::KEYS, ...self::MISSING];
        $blocks = [];
        $count = $this->int(7);
        for ($i = 0; $i < $count; $i++) {
            $blocks[] = $this->block(false, $keys);
        }

        return new FormDocument($blocks);
    }

    /**
     * Every block of $document other than sections, in reading order, as
     * object ids.
     *
     * @return list<int>
     */
    private static function leaves(FormDocument $document): array
    {
        $ids = [];
        foreach ($document->blocks as $block) {
            foreach ($block instanceof Section ? $block->children : [$block] as $child) {
                $ids[] = spl_object_id($child);
            }
        }

        return $ids;
    }

    /** @return list<array{0: ?string, 1: ?string, 2: ?Placement}> each section's role, legend, and placement object */
    private static function sections(FormDocument $document): array
    {
        $sections = [];
        foreach ($document->blocks as $block) {
            if ($block instanceof Section) {
                $sections[] = [$block->role, $block->legend, $block->placement];
            }
        }

        return $sections;
    }

    public function testEveryActionOnEveryRowOfSeededForms(): void
    {
        $this->random = new SeededRandom(self::SEED);
        $parser = new FormParser(FieldTypes::standard());
        $serializer = new FormSerializer();
        $fields = self::fields();
        $done = [];
        for ($n = 0; $n < 200; $n++) {
            $document = $this->document();
            $actions = [EditorAction::apply(), EditorAction::save(), EditorAction::addSection()];
            foreach (self::KEYS as $key) {
                if (!in_array($key, $document->fieldKeys(), true)) {
                    $actions[] = EditorAction::add($key);
                }
            }
            foreach ($this->editor->rows($document, $fields) as $row) {
                $path = EditorPath::parse($row->path);
                self::assertNotNull($path);
                foreach ([EditorAction::UP, EditorAction::DOWN, EditorAction::HIDE, EditorAction::REMOVE, EditorAction::UNGROUP] as $verb) {
                    $fits = match ($verb) {
                        EditorAction::UP => $row->canMoveUp,
                        EditorAction::DOWN => $row->canMoveDown,
                        EditorAction::HIDE => $row->kind === 'field',
                        EditorAction::REMOVE => !in_array($row->kind, ['field', 'section'], true),
                        default => $row->kind === 'section',
                    };
                    $action = EditorAction::on($verb, $path);
                    if (!$fits) {
                        try {
                            $this->editor->act($document, $action, $fields);
                            self::fail('Form ' . $n . ': ' . $action->value() . ' on a ' . $row->kind . ' row was accepted.');
                        } catch (\DomainException) {
                        }

                        continue;
                    }
                    $actions[] = $action;
                }
            }

            foreach ($actions as $action) {
                $label = 'Form ' . $n . ', ' . $action->value();
                $before = $document;
                $named = null;
                if ($action->path !== null) {
                    $named = $action->path->in($before);
                    self::assertNotNull($named, $label);
                }
                $after = $this->editor->act($before, $action, $fields);
                $done[$action->verb] = ($done[$action->verb] ?? 0) + 1;

                $text = $serializer->serialize($after);
                $parsed = $parser->parse($text);
                self::assertEquals($after, $parsed, $label);
                self::assertSame($text, $serializer->serialize($parsed), $label);

                // Every block not named by the action is the same object, in the same relative order.
                // A moved section carries its children with it.
                $excluded = $named === null ? [] : [spl_object_id($named)];
                if ($named instanceof Section && $action->verb !== EditorAction::UNGROUP) {
                    $excluded = [...$excluded, ...array_map(spl_object_id(...), $named->children)];
                }
                $keptBefore = array_values(array_diff(self::leaves($before), $excluded));
                $keptAfter = array_values(array_intersect(self::leaves($after), $keptBefore));
                self::assertSame($keptBefore, $keptAfter, $label);
                $newLeaves = array_diff(self::leaves($after), self::leaves($before));
                self::assertCount($action->verb === EditorAction::ADD ? 1 : 0, $newLeaves, $label);
                $sectionsBefore = self::sections($before);
                if ($action->verb === EditorAction::UNGROUP) {
                    array_splice($sectionsBefore, count(self::sections(new FormDocument(array_slice($before->blocks, 0, $action->path->root - 1)))), 1);
                }
                if ($action->verb === EditorAction::ADD_SECTION) {
                    $sectionsBefore[] = [null, FormEditor::NEW_SECTION, null];
                }
                $sectionsAfter = self::sections($after);
                self::assertSame(count($sectionsBefore), count($sectionsAfter), $label);
                foreach ($sectionsBefore as $i => [$role, $legend, $placement]) {
                    if (!in_array($action->verb, [EditorAction::UP, EditorAction::DOWN], true) || !$named instanceof Section) {
                        self::assertSame([$role, $legend], [$sectionsAfter[$i][0], $sectionsAfter[$i][1]], $label);
                        self::assertSame($placement, $sectionsAfter[$i][2], $label);
                    }
                }
                if (in_array($action->verb, [EditorAction::APPLY, EditorAction::SAVE], true)) {
                    self::assertSame($before, $after, $label);
                }
                if ($action->verb === EditorAction::HIDE || $action->verb === EditorAction::REMOVE) {
                    self::assertNotContains(spl_object_id($named), self::leaves($after), $label);
                }
            }
        }
        foreach ([EditorAction::UP, EditorAction::DOWN, EditorAction::HIDE, EditorAction::REMOVE, EditorAction::UNGROUP, EditorAction::ADD, EditorAction::ADD_SECTION] as $verb) {
            self::assertGreaterThan(20, $done[$verb] ?? 0, $verb . ' is exercised.');
        }
    }

    // ------------------------------------------------------------ T-EDT-3

    public function testMovesAcrossSectionBoundaries(): void
    {
        $p = self::text('P');
        $q = self::text('Q');
        $x = self::text('X');
        $y = self::text('Y');
        $first = new Section(null, 'First', [$x], new Placement(null, null, 'full'));
        $second = new Section('links', 'Second', [$y]);
        $document = new FormDocument([$p, $first, $q, $second]);

        // A root block below a section moves to the end of that section.
        $into = $this->act($document, 'up-3');
        self::assertEquals(new FormDocument([$p, new Section(null, 'First', [$x, $q], new Placement(null, null, 'full')), $second]), $into);
        self::assertSame($first->placement, $into->blocks[1]->placement);
        // A root block above a section moves to the start of that section.
        self::assertEquals(new FormDocument([new Section(null, 'First', [$p, $x], new Placement(null, null, 'full')), $q, $second]), $this->act($document, 'down-1'));
        self::assertEquals(new FormDocument([$p, $first, new Section('links', 'Second', [$q, $y])]), $this->act($document, 'down-3'));
        // A first child moves out of its section, just before it; a last child just after it.
        self::assertEquals(new FormDocument([$p, $x, new Section(null, 'First', [], new Placement(null, null, 'full')), $q, $second]), $this->act($document, 'up-2-1'));
        self::assertEquals(new FormDocument([$p, new Section(null, 'First', [], new Placement(null, null, 'full')), $x, $q, $second]), $this->act($document, 'down-2-1'));
        self::assertEquals(new FormDocument([$p, $first, $q, $y, new Section('links', 'Second', [])]), $this->act($document, 'up-4-1'));
        self::assertEquals(new FormDocument([$p, $first, $q, new Section('links', 'Second', []), $y]), $this->act($document, 'down-4-1'));
        // Sections swap with the root block next to them, sections included.
        self::assertEquals(new FormDocument([$first, $p, $q, $second]), $this->act($document, 'up-2'));
        self::assertEquals(new FormDocument([$p, $q, $first, $second]), $this->act($document, 'down-2'));
        $two = new FormDocument([$first, $second]);
        self::assertSame([$second, $first], $this->act($two, 'up-2')->blocks);
        self::assertSame([$second, $first], $this->act($two, 'down-1')->blocks);
        // Within a section, children swap.
        $pair = new FormDocument([new Section(null, 'Pair', [$x, $y])]);
        self::assertSame([$y, $x], $this->act($pair, 'up-1-2')->blocks[0]->children);
        self::assertSame([$y, $x], $this->act($pair, 'down-1-1')->blocks[0]->children);
        // Root blocks with no section between them swap.
        self::assertSame([$q, $p], $this->act(new FormDocument([$p, $q]), 'up-2')->blocks);
    }

    // ------------------------------------------------------------ T-EDT-4

    public function testApplyLabels(): void
    {
        [$a, $b, $c, $d] = self::KEYS;
        $placed = new Placement(2, 3, '4');
        $document = new FormDocument([
            new FieldSlot($a, 'Old'),
            new Section(null, 'Main', [new FieldSlot($b, 'Old'), new FieldSlot($c), new FieldSlot($d, 'Keep', $placed)]),
            new FieldSlot(self::MISSING[0]),
            self::text('Text'),
        ]);
        $sixty = str_repeat("\u{E9}", 60);
        $applied = $this->editor->apply($document, self::fields(), [
            1 => '  New title  ',
            '2-1' => 'Field 2',
            '2-2' => ' ' . $sixty . ' ',
            '2-3' => ['not', 'a', 'string'],
            '3' => 'For a missing slot',
            '4' => 'For a paragraph',
            '9' => 'No such path',
            '2' => 'A section is not a field row',
        ], [], []);
        self::assertSame([], $applied->problems);
        self::assertSame([], $applied->typed);
        self::assertEquals(new FormDocument([
            new FieldSlot($a, 'New title'),
            new Section(null, 'Main', [new FieldSlot($b), new FieldSlot($c, $sixty), new FieldSlot($d, 'Keep', $placed)]),
            new FieldSlot(self::MISSING[0]),
            self::text('Text'),
        ]), $applied->document);
        self::assertSame($document->blocks[1]->children[2], $applied->document->blocks[1]->children[2]);
        self::assertSame($document->blocks[2], $applied->document->blocks[2]);

        // Empty clears; refused texts keep the slot's label and are typed back.
        $refused = $this->editor->apply($document, self::fields(), [
            '1' => '   ',
            '2-1' => str_repeat('x', 61),
            '2-2' => "Tab\there",
            '2-3' => "Right\u{202E}left",
        ], [], []);
        self::assertEquals([
            new Problem('label-2-1', 'problem.form.label', ['max' => 60]),
            new Problem('label-2-2', 'problem.form.label', ['max' => 60]),
            new Problem('label-2-3', 'problem.form.label', ['max' => 60]),
        ], $refused->problems);
        self::assertSame(['label-2-1' => str_repeat('x', 61), 'label-2-2' => "Tab\there", 'label-2-3' => "Right\u{202E}left"], $refused->typed);
        self::assertEquals(new FormDocument([
            new FieldSlot($a),
            new Section(null, 'Main', [new FieldSlot($b, 'Old'), new FieldSlot($c), new FieldSlot($d, 'Keep', $placed)]),
            new FieldSlot(self::MISSING[0]),
            self::text('Text'),
        ]), $refused->document);

        // Invalid UTF-8 is refused and typed back as valid text.
        $broken = $this->editor->apply($document, self::fields(), ['1' => "Bad\xC3("], [], []);
        self::assertCount(1, $broken->problems);
        self::assertTrue(mb_check_encoding($broken->typed['label-1'], 'UTF-8'));
        self::assertSame('Old', $broken->document->blocks[0]->label);

        // Nothing posted: the same document.
        $same = $this->editor->apply($document, self::fields(), [], [], []);
        self::assertSame($document, $same->document);
        self::assertSame($document, $this->editor->apply($document, self::fields(), ['1' => 'Old'], [], ['2-1' => 'auto'])->document);
    }

    public function testApplyLegends(): void
    {
        $child = self::text('Inside');
        $document = new FormDocument([new Section('details', 'Main', [$child]), new Section(null, null, []), new Section(null, 'Third', []), self::text('Root')]);
        $hundred = str_repeat('y', 100);
        $applied = $this->editor->apply($document, self::fields(), [], ['1' => " Renamed\u{A0}", '2' => $hundred, '3' => '', '4' => 'Not a section', '1-1' => 'Not a section'], []);
        self::assertSame([], $applied->problems);
        self::assertEquals(new FormDocument([new Section('details', 'Renamed', [$child]), new Section(null, $hundred, []), new Section(null, null, []), self::text('Root')]), $applied->document);
        self::assertSame($child, $applied->document->blocks[0]->children[0]);
        self::assertSame($document->blocks[3], $applied->document->blocks[3]);

        $refused = $this->editor->apply($document, self::fields(), [], ['1' => $hundred . 'y', '3' => "Line\nbreak", '2' => 7], []);
        self::assertEquals([
            new Problem('legend-1', 'problem.form.legend', ['max' => 100]),
            new Problem('legend-3', 'problem.form.legend', ['max' => 100]),
        ], $refused->problems);
        self::assertSame(['legend-1' => $hundred . 'y', 'legend-3' => "Line\nbreak"], $refused->typed);
        self::assertSame($document, $refused->document);
    }

    public function testApplyWidths(): void
    {
        [$a, $b, $c, $d, $e, $f] = self::KEYS;
        $document = new FormDocument([
            new FieldSlot($a),
            new FieldSlot($b, 'B', new Placement(4, 7, 'full')),
            new FieldSlot($c, null, new Placement(null, null, '5')),
            new FieldSlot($d, null, new Placement(3)),
            new FieldSlot($e, null, new Placement(null, null, '6')),
            new Section(null, 'S', [new FieldSlot($f, null, new Placement(null, null, 'full'))], new Placement(null, null, 'full')),
            new FieldSlot(self::MISSING[0], null, new Placement(null, null, '6')),
        ]);
        $applied = $this->editor->apply($document, self::fields(), [], [], [
            '1' => 'two-thirds',
            '2' => 'auto',
            '3' => 'other',
            '4' => 'one-third',
            '5' => 'wide',
            '6' => 'half',
            '6-1' => 'auto',
            '7' => 'full',
        ]);
        self::assertSame([], $applied->problems);
        self::assertEquals(new FormDocument([
            new FieldSlot($a, null, new Placement(null, null, '8')),
            new FieldSlot($b, 'B', new Placement(4, 7)),
            new FieldSlot($c, null, new Placement(null, null, '5')),
            new FieldSlot($d, null, new Placement(3, null, '4')),
            new FieldSlot($e, null, new Placement(null, null, '6')),
            new Section(null, 'S', [new FieldSlot($f)], new Placement(null, null, 'full')),
            new FieldSlot(self::MISSING[0], null, new Placement(null, null, '6')),
        ]), $applied->document);
        foreach ([2, 4, 6] as $i) {
            self::assertSame($document->blocks[$i], $applied->document->blocks[$i]);
        }
        self::assertSame($document->blocks[5]->placement, $applied->document->blocks[5]->placement);
        $full = $this->editor->apply($document, self::fields(), [], [], ['1' => 'full', '5' => 'half', '3' => 'one-third', '2' => ['full']]);
        self::assertSame('full', $full->document->blocks[0]->placement->span);
        self::assertSame($document->blocks[4], $full->document->blocks[4]);
        self::assertSame('4', $full->document->blocks[2]->placement->span);
        self::assertSame($document->blocks[1], $full->document->blocks[1]);
        self::assertSame(['auto' => null, 'full' => 'full', 'two-thirds' => '8', 'half' => '6', 'one-third' => '4'], FormEditor::WIDTHS);
    }

    // ------------------------------------------------------------ T-EDT-5

    public function testActionsThatDoNotFitAreRefused(): void
    {
        [$a, $b] = self::KEYS;
        $document = new FormDocument([
            new Heading(2, [new TextRun('Top')]),
            new Section(null, 'Main', [new FieldSlot($a), new FieldSlot(self::MISSING[0])]),
            self::text('Last'),
        ]);
        foreach ([
            'up-1', 'down-3', 'up-9', 'down-4', 'up-2-3', 'up-1-1', 'hide-1', 'hide-2', 'hide-2-2', 'hide-9', 'remove-2', 'remove-2-1', 'remove-7-1',
            'ungroup-1', 'ungroup-2-1', 'ungroup-3', 'add-' . $a, 'add-' . self::MISSING[1], 'add-' . self::MISSING[0],
        ] as $do) {
            try {
                $this->act($document, $do);
                self::fail($do . ' was accepted.');
            } catch (\DomainException) {
            }
        }
        self::assertSame(['1', '2', '2-1', '2-2', '3'], self::paths($document));
        // The fitting ones work.
        self::assertSame([$a], $this->act($document, 'remove-2-2')->fieldKeys());
        self::assertSame([self::MISSING[0]], $this->act($document, 'hide-2-1')->fieldKeys());
        self::assertSame([$a, self::MISSING[0], $b], $this->act($document, 'add-' . $b)->fieldKeys());
    }

    public function testActionsThatWouldBreakAFormRuleAreRefused(): void
    {
        // 1 root + 4,998 dividers = 4,999 elements; a section and its legend would make 5,001.
        $full = new FormDocument(array_fill(0, 4998, new Divider()));
        try {
            $this->editor->act($full, EditorAction::addSection(), self::fields());
            self::fail('A section over the element limit was added.');
        } catch (\DomainException) {
        }
        self::assertCount(4998, $this->editor->act(new FormDocument(array_fill(0, 4997, new Divider())), EditorAction::addSection(), self::fields())->blocks);

        // Six levels of inline elements fit in the root (2 + 6 = 8 levels) but not in a section.
        $deep = [new TextRun('deep')];
        for ($i = 0; $i < 6; $i++) {
            $deep = [new Strong($deep)];
        }
        $document = new FormDocument([new Section(null, 'S', []), new Paragraph($deep), new Section(null, 'T', [])]);
        $rows = $this->editor->rows($document, self::fields());
        self::assertSame([false, false], [$rows[1]->canMoveUp, $rows[1]->canMoveDown]);
        self::assertSame([true, false], [$rows[2]->canMoveUp, $rows[2]->canMoveDown]);
        foreach (['up-2', 'down-2'] as $do) {
            try {
                $this->act($document, $do);
                self::fail($do . ' broke the depth limit.');
            } catch (\DomainException) {
            }
        }
    }

    public function testParseOfPostedActions(): void
    {
        foreach (['apply', 'save', 'add-section', 'add-' . self::KEYS[0], 'up-1', 'down-12-3', 'hide-9999-9999', 'remove-2', 'ungroup-4'] as $value) {
            self::assertSame($value, EditorAction::parse($value)?->value(), $value);
        }
        foreach (['', 'Apply', 'up', 'up-', 'up-0', 'up-01', 'up-1-0', 'up-1-2-3', 'up-10000', 'up-1 ', 'hide-a', 'add-', 'add-SECTION', 'add-' . strtoupper(self::KEYS[0]),
            'add-abc', 'move-1', 'save-1', "up-1\n", 'remove-1-', 'ungroup--1'] as $value) {
            self::assertNull(EditorAction::parse($value), var_export($value, true));
        }
        $action = EditorAction::parse('down-12-3');
        self::assertSame([EditorAction::DOWN, 12, 3, null], [$action->verb, $action->path->root, $action->path->child, $action->fieldKey]);
        self::assertSame([EditorAction::ADD, null, self::KEYS[1]], [EditorAction::parse('add-' . self::KEYS[1])->verb, EditorAction::parse('add-' . self::KEYS[1])->path, EditorAction::parse('add-' . self::KEYS[1])->fieldKey]);
        self::assertNull(EditorPath::parse('0'));
        self::assertNull((new EditorPath(2, 1))->in(new FormDocument([self::text('a'), self::text('b')])));
    }

    // ------------------------------------------------------------ T-EDT-6

    /** @return array{path: ?string, control: string}|null */
    private function focus(FormDocument $document, string $do): ?array
    {
        $action = EditorAction::parse($do);
        self::assertNotNull($action);
        $after = $this->editor->act($document, $action, self::fields());

        return $this->editor->focusAfter($document, $action, $after);
    }

    public function testFocusAfterMoves(): void
    {
        $p = self::text('P');
        $document = new FormDocument([$p, new Section(null, 'S', [self::text('X'), self::text('Y')]), self::text('Q'), self::text('R')]);
        // Within a section; into and out of sections; at the edges the other control.
        self::assertSame(['path' => '2-1', 'control' => 'up'], $this->focus($document, 'up-2-2'));
        self::assertSame(['path' => '2', 'control' => 'up'], $this->focus($document, 'up-2-1'));
        self::assertSame(['path' => '1-1', 'control' => 'down'], $this->focus($document, 'down-1'));
        self::assertSame(['path' => '2-3', 'control' => 'up'], $this->focus($document, 'up-3'));
        self::assertSame(['path' => '3', 'control' => 'down'], $this->focus($document, 'down-2-2'));
        self::assertSame(['path' => '1', 'control' => 'down'], $this->focus($document, 'up-2'));
        self::assertSame(['path' => '4', 'control' => 'up'], $this->focus($document, 'down-3'));
        self::assertSame(['path' => '3', 'control' => 'up'], $this->focus($document, 'up-4'));
        self::assertSame(['path' => '1', 'control' => 'down'], $this->focus(new FormDocument([$p, self::text('Q')]), 'up-2'));
    }

    public function testFocusAfterRemovalsUngroupAndAdding(): void
    {
        [$a, $b, $c] = self::KEYS;
        $document = new FormDocument([
            new FieldSlot($a),
            new Section(null, 'S', [new FieldSlot($b), self::text('X')]),
            new Section(null, 'Empty', []),
            new Divider(),
        ]);
        self::assertSame(['path' => '1', 'control' => 'first'], $this->focus($document, 'hide-1'));
        self::assertSame(['path' => '2-1', 'control' => 'first'], $this->focus($document, 'hide-2-1'));
        // The last child of a section: the next row is the next section.
        self::assertSame(['path' => '3', 'control' => 'first'], $this->focus($document, 'remove-2-2'));
        // The last row: the "Add a section" button.
        self::assertSame(['path' => null, 'control' => 'add-section'], $this->focus($document, 'remove-4'));
        self::assertSame(['path' => null, 'control' => 'add-section'], $this->focus(new FormDocument([self::text('Only')]), 'remove-1'));
        self::assertSame(['path' => null, 'control' => 'add-section'], $this->focus(new FormDocument([new Section(null, 'S', [new FieldSlot($a)])]), 'hide-1-1'));
        // Ungroup: the first former child; an empty section as remove.
        self::assertSame(['path' => '2', 'control' => 'first'], $this->focus($document, 'ungroup-2'));
        self::assertSame(['path' => '3', 'control' => 'first'], $this->focus($document, 'ungroup-3'));
        self::assertSame(['path' => null, 'control' => 'add-section'], $this->focus(new FormDocument([new Section(null, 'E', [])]), 'ungroup-1'));
        // Adding a field and a section.
        self::assertSame(['path' => '2-3', 'control' => 'up'], $this->focus($document, 'add-' . $c));
        self::assertSame(['path' => '1', 'control' => 'first'], $this->focus(new FormDocument([]), 'add-' . $c));
        self::assertSame(['path' => '2', 'control' => 'up'], $this->focus(new FormDocument([self::text('P')]), 'add-' . $c));
        self::assertSame(['path' => '5', 'control' => 'legend'], $this->focus($document, 'add-section'));
        self::assertSame(['path' => '1', 'control' => 'legend'], $this->focus(new FormDocument([]), 'add-section'));
        self::assertNull($this->focus($document, 'apply'));
        self::assertNull($this->focus($document, 'save'));
        self::assertEquals(new FormDocument([new FieldSlot($c)]), $this->act(new FormDocument([]), 'add-' . $c));
        self::assertEquals(new Section(null, 'New section', []), $this->act(new FormDocument([]), 'add-section')->blocks[0]);
    }
}
