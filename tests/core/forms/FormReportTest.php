<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormReport;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\LineBreak;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\FieldDef;

/**
 * T-EDT-7: unknown slot keys (another table's field included), fields not
 * on the form, required fields not on the form, pictures, and links with
 * no text. Synthetic keys and text only.
 */
#[CoversClass(FormReport::class)]
final class FormReportTest extends TestCase
{
    private const TABLE = 'tbl2tbl2tbl2';
    private const OTHER_TABLE = 'oth2oth2oth2';
    private const LINK = 'https://example.com/';

    private static function field(string $key, string $name, bool $required, int $position, string $table = self::TABLE): FieldDef
    {
        return new FieldDef($key, $table, $name, 'text', [], $required, '', $position, true);
    }

    public function testEverythingTheReportCounts(): void
    {
        $title = self::field('aaaaaaaaaaaa', 'Title', false, 1);
        $notes = self::field('bbbbbbbbbbbb', 'Notes', true, 2);
        $count = self::field('cccccccccccc', 'Count', false, 3);
        $code = self::field('dddddddddddd', 'Code', true, 4);
        $foreign = self::field('eeeeeeeeeeee', 'Elsewhere', false, 1, self::OTHER_TABLE);
        $fields = [$title, $notes, $count, $code];

        $document = new FormDocument([
            new FieldSlot('zzzzzzzzzzzz'),
            new Image(7, 'First'),
            new Paragraph([
                new Link(self::LINK, [new TextRun('Read')]),
                new Link(self::LINK, []),
                new Strong([new Link(self::LINK, [new TextRun(" \t\n")])]),
                new Link(self::LINK, [new Emphasis([new TextRun('deep')])]),
                new Link(self::LINK, [new LineBreak(), new Strong([])]),
            ]),
            new Section(null, 'Main', [
                new FieldSlot($count->key),
                new FieldSlot($foreign->key),
                new Image(3, ''),
                new Heading(2, [new Emphasis([new Link(self::LINK, [new TextRun("\u{A0}")])])]),
            ]),
            new FieldSlot($title->key),
            new Image(7, 'Again'),
        ]);

        $report = FormReport::of($document, $fields);

        self::assertSame(['zzzzzzzzzzzz', $foreign->key], $report->unknownKeys);
        self::assertSame([$notes, $code], $report->notOnForm);
        self::assertSame([$notes, $code], $report->requiredNotOnForm);
        self::assertSame([7, 3, 7], $report->images);
        self::assertSame([], $report->imagesNotShown);
        self::assertSame(4, $report->emptyLinks);

        $hidden = $report->withImagesNotShown([7, 99]);
        self::assertSame([7], $hidden->imagesNotShown);
        self::assertSame([7, 3, 7], $hidden->images);
        self::assertSame($report->unknownKeys, $hidden->unknownKeys);
        self::assertSame(4, $hidden->emptyLinks);
        self::assertSame([], $report->imagesNotShown, 'The report itself is unchanged.');
    }

    public function testAnEmptyFormAndAFullFormOfTheTable(): void
    {
        $title = self::field('aaaaaaaaaaaa', 'Title', true, 1);
        $notes = self::field('bbbbbbbbbbbb', 'Notes', false, 2);

        $empty = FormReport::of(new FormDocument([]), [$title, $notes]);
        self::assertSame([], $empty->unknownKeys);
        self::assertSame([$title, $notes], $empty->notOnForm);
        self::assertSame([$title], $empty->requiredNotOnForm);
        self::assertSame([], $empty->images);
        self::assertSame(0, $empty->emptyLinks);

        $full = FormReport::of(new FormDocument([new Section('details', 'Details', [new FieldSlot($notes->key), new FieldSlot($title->key)])]), [$title, $notes]);
        self::assertSame([], $full->unknownKeys);
        self::assertSame([], $full->notOnForm);
        self::assertSame([], $full->requiredNotOnForm);
    }
}
