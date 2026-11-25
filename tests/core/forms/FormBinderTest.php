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
use TiffinDB\Core\Forms\BindData;
use TiffinDB\Core\Forms\BoundDivider;
use TiffinDB\Core\Forms\BoundField;
use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Forms\BoundHeading;
use TiffinDB\Core\Forms\BoundImage;
use TiffinDB\Core\Forms\BoundParagraph;
use TiffinDB\Core\Forms\BoundSection;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FileItem;
use TiffinDB\Core\Forms\FormBinder;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormGenerator;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\LinkItem;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Core\Schema\ProjectFixture;

require_once __DIR__ . '/../schema/ProjectFixture.php';

/** T-FORM-9 and T-FORM-13: FormBinder joins a form with field definitions and plain values (M2 spec section 5.7). */
#[CoversClass(FormBinder::class)]
#[CoversClass(BoundForm::class)]
#[CoversClass(BoundField::class)]
#[CoversClass(BoundSection::class)]
#[CoversClass(BoundHeading::class)]
#[CoversClass(BoundParagraph::class)]
#[CoversClass(BoundDivider::class)]
#[CoversClass(BoundImage::class)]
final class FormBinderTest extends TestCase
{
    use ProjectFixture;

    private string $items = '';
    private string $name = '';
    private string $price = '';
    private string $due = '';
    private string $serial = '';
    private string $notes = '';
    private string $room = '';
    private string $tags = '';
    private string $photos = '';
    private string $roomsTable = '';

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-form-binder-test-1');
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $this->items = $items->key;
        $this->roomsTable = $rooms->key;
        $this->name = $this->schema()->fields($items->key)[0]->key;
        $this->price = $editor->addField($items->key, 'Price', 'number', ['decimals' => 2], false, '')->key;
        $this->due = $editor->addField($items->key, 'Due', 'date', [], false, '')->key;
        $this->serial = $editor->addField($items->key, 'Serial', 'auto_number', [], false, '')->key;
        $this->notes = $editor->addField($items->key, 'Notes', 'long_text', [], false, '')->key;
        $this->room = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->tags = $editor->addRelation($items->key, 'Tags', $rooms->key, Cardinality::Many, Cardinality::Many, 'Tagged', false, '')->key;
        $this->photos = $editor->addField($items->key, 'Photos', 'attachment', [], false, '')->key;
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function bind(FormDocument $form, BindData $data): BoundForm
    {
        return (new FormBinder())->bind($form, $this->items, $this->schema()->fields($this->items), $this->types, $data);
    }

    /** @return array<string, BoundField> */
    private static function fieldsOf(BoundForm $form): array
    {
        $fields = [];
        $walk = static function (array $nodes) use (&$walk, &$fields): void {
            foreach ($nodes as $node) {
                if ($node instanceof BoundField) {
                    $fields[$node->field->key] = $node;
                } elseif ($node instanceof BoundSection) {
                    $walk($node->children);
                }
            }
        };
        $walk($form->nodes);

        return $fields;
    }

    public function testGeneratedFormBoundWithValuesSubmittedTextAndProblems(): void
    {
        $form = (new FormGenerator())->generate($this->schema(), $this->items);
        $problem = new Problem($this->price, 'problem.number.invalid');
        // T-FORM-11: link details and file ids travel through the binder unchanged.
        $detail = $this->schema()->fields($this->roomsTable)[0];
        $links = [new LinkItem($this->roomsTable, 3, 'Kitchen', $detail, 'Ground floor'), new LinkItem($this->roomsTable, 4, null)];
        $files = [new FileItem(9, 'receipt.pdf', 'application/pdf', 2048)];
        $bound = $this->bind($form, new BindData(
            values: [$this->name => 'Toaster', $this->price => 12.5, $this->due => null, $this->serial => 7, $this->notes => "Line one\nLine two"],
            submitted: [$this->price => '12,50 typed'],
            problems: [$this->price => [$problem]],
            links: [$this->tags => $links, $this->name => $links],
            files: [$this->photos => $files, $this->name => $files],
        ));
        $fields = self::fieldsOf($bound);

        self::assertSame('Toaster', $fields[$this->name]->inputText);
        self::assertFalse($fields[$this->name]->submitted);
        self::assertSame('Toaster', $fields[$this->name]->value);
        self::assertSame('Name', $fields[$this->name]->label);
        self::assertSame([], $fields[$this->name]->links, 'Links only belong to relation fields.');
        self::assertSame([], $fields[$this->name]->files, 'Files only belong to attachment fields.');

        self::assertSame('12,50 typed', $fields[$this->price]->inputText);
        self::assertTrue($fields[$this->price]->submitted);
        self::assertSame(12.5, $fields[$this->price]->value);
        self::assertSame([$problem], $fields[$this->price]->problems);
        self::assertSame([], $fields[$this->name]->problems);

        self::assertSame('', $fields[$this->due]->inputText);
        self::assertNull($fields[$this->due]->value);
        self::assertSame('7', $fields[$this->serial]->inputText);
        self::assertFalse($fields[$this->serial]->editable, 'Auto-numbers are not typed.');
        self::assertSame("Line one\nLine two", $fields[$this->notes]->inputText);
        self::assertTrue($fields[$this->notes]->spanFull);
        self::assertFalse($fields[$this->name]->spanFull);

        self::assertFalse($fields[$this->room]->editable);
        self::assertFalse($fields[$this->tags]->editable);
        self::assertFalse($fields[$this->photos]->editable);
        self::assertSame($links, $fields[$this->tags]->links);
        self::assertSame([], $fields[$this->room]->links);
        self::assertSame($files, $fields[$this->photos]->files);
        self::assertSame(9, $fields[$this->photos]->files[0]->id);

        // A lone slot in a links section hides its label (the legend names it); Details and Files do not.
        self::assertTrue($fields[$this->tags]->labelHidden);
        self::assertFalse($fields[$this->room]->labelHidden);
        self::assertFalse($fields[$this->photos]->labelHidden);
        self::assertFalse($fields[$this->name]->isNew);

        self::assertSame([$this->name, $this->price, $this->due, $this->notes], $bound->editableKeys());
        self::assertTrue($bound->hasGrid());
        self::assertSame(['section', 'section', 'section'], array_map(static fn ($n): string => $n->kind(), $bound->nodes));
        self::assertTrue($bound->nodes[0]->grid);
        self::assertFalse($bound->nodes[1]->grid);
        self::assertSame('Files', $bound->nodes[1]->legend);
        self::assertSame('links', $bound->nodes[2]->role);
    }

    public function testSlotsForUnknownKeysAreDroppedAndImagesAreBound(): void
    {
        $form = new FormDocument([
            new Heading(2, [new TextRun('Card')]),
            new FieldSlot($this->due, 'When', new Placement(null, null, 'full')),
            new Section('details', 'Main', [
                new FieldSlot('k2xq7vn4mz3t'),
                new Image(4, 'Photo'),
                new FieldSlot($this->name),
                new Paragraph([new TextRun('Note')]),
                new Divider(),
            ], new Placement(null, null, 'full')),
            new Image(5, ''),
            new FieldSlot('b7xk2mq4nz3p'),
            new Section('links', 'Lone', [new FieldSlot($this->tags), new FieldSlot($this->room)]),
            new Section('links', 'Lone after drop', [new FieldSlot('p3zk7mq2xn4w'), new FieldSlot($this->photos)]),
        ]);
        $bound = $this->bind($form, new BindData(isNew: true, servableImages: [4]));

        self::assertSame(['heading', 'field', 'section', 'image', 'section', 'section'], array_map(static fn ($n): string => $n->kind(), $bound->nodes));
        self::assertInstanceOf(BoundHeading::class, $bound->nodes[0]);
        self::assertSame(2, $bound->nodes[0]->level);
        self::assertEquals([new TextRun('Card')], $bound->nodes[0]->children);
        self::assertSame('When', $bound->nodes[1]->label);
        self::assertTrue($bound->nodes[1]->spanFull);
        self::assertTrue($bound->nodes[1]->isNew);
        $main = $bound->nodes[2];
        self::assertInstanceOf(BoundSection::class, $main);
        self::assertTrue($main->spanFull);
        self::assertSame(['image', 'field', 'paragraph', 'divider'], array_map(static fn ($n): string => $n->kind(), $main->children));
        // T-FORM-11: every image block is a BoundImage; servable only when BindData lists its id (P173).
        self::assertEquals(new BoundImage(4, 'Photo', true), $main->children[0]);
        self::assertEquals(new BoundImage(5, '', false), $bound->nodes[3]);
        self::assertInstanceOf(BoundParagraph::class, $main->children[2]);
        self::assertInstanceOf(BoundDivider::class, $main->children[3]);
        // Two slots in a links section: labels stay visible.
        self::assertFalse($bound->nodes[4]->children[0]->labelHidden);
        // labelHidden is decided by the document's slots, before unknown keys are dropped.
        self::assertFalse($bound->nodes[5]->children[0]->labelHidden);
        self::assertSame([$this->due, $this->name], $bound->editableKeys());
        self::assertTrue($bound->hasGrid());
    }

    public function testFieldsOfAnotherTableAreIgnoredAndNoGridWithoutDetails(): void
    {
        $roomName = $this->schema()->fields($this->roomsTable)[0]->key;
        $form = new FormDocument([new Section('main', null, [new FieldSlot($roomName), new FieldSlot($this->name)])]);
        $all = [...$this->schema()->fields($this->items), ...$this->schema()->fields($this->roomsTable)];
        $bound = (new FormBinder())->bind($form, $this->items, $all, $this->types, new BindData());
        self::assertSame([$this->name], $bound->editableKeys());
        self::assertFalse($bound->hasGrid());
        self::assertFalse($bound->nodes[0]->grid);
    }

    // T-FORM-13: spans and column mode (M5 spec section 8).

    public function testEveryBoundKindCarriesItsSpan(): void
    {
        $span = static fn (string $s): Placement => new Placement(null, null, $s);
        $form = new FormDocument([
            new Section('details', 'Main', [
                new FieldSlot($this->name, null, $span('8')),
                new FieldSlot($this->price, null, $span('full')),
                new FieldSlot($this->due),
                new Heading(3, [new TextRun('Small')], $span('4')),
                new Paragraph([new TextRun('Text')], $span('6')),
                new Divider($span('12')),
                new Image(4, 'Photo', new Placement(2, 3, '3')),
                new Image(5, 'Placed', new Placement(2, null, null)),
            ], $span('full')),
            new Section('main', null, [new FieldSlot($this->notes)], $span('6')),
            new Section('main', null, []),
        ]);
        $bound = $this->bind($form, new BindData(servableImages: [4]));

        [$main, $half, $plain] = $bound->nodes;
        self::assertInstanceOf(BoundSection::class, $main);
        self::assertSame('full', $main->span);
        self::assertTrue($main->spanFull);
        self::assertSame(
            ['8', 'full', null, '4', '6', '12', '3', null],
            array_map(static fn ($n): ?string => $n->span, $main->children),
        );
        self::assertSame(
            ['8', 'full', null, '4', '6', '12', '3', null],
            array_map(static fn ($n): ?string => $n->span(), $main->children),
        );
        self::assertFalse($main->children[0]->spanFull);
        self::assertTrue($main->children[1]->spanFull);
        self::assertEquals(new BoundImage(4, 'Photo', true, new Placement(2, 3, '3'), '3'), $main->children[6]);
        self::assertTrue($main->columns, 'Numeric spans put the Details section in column mode.');
        self::assertTrue($main->grid, 'grid stays as built; the template chooses columns first.');
        self::assertSame('6', $half->span);
        self::assertFalse($half->spanFull);
        self::assertFalse($half->columns, 'Its only child has no span.');
        self::assertNull($plain->span);
        self::assertFalse($plain->columns);
        self::assertTrue($bound->columns, 'A root section spans six columns.');
    }

    public function testColumnsComeOnlyFromDirectBoundChildrenWithANumericSpan(): void
    {
        $span = static fn (string $s): Placement => new Placement(null, null, $s);

        // Only "full" and no span: no column mode anywhere.
        $full = $this->bind(new FormDocument([
            new Section('details', null, [new FieldSlot($this->name, null, $span('full')), new FieldSlot($this->due)], $span('full')),
            new FieldSlot($this->price, null, $span('full')),
        ]), new BindData());
        self::assertFalse($full->columns);
        self::assertFalse($full->nodes[0]->columns);
        self::assertTrue($full->nodes[0]->grid);

        // A heading alone gives column mode.
        $heading = $this->bind(new FormDocument([
            new Section('main', null, [new Heading(2, [new TextRun('Card')], $span('6')), new FieldSlot($this->name)]),
        ]), new BindData());
        self::assertTrue($heading->nodes[0]->columns);
        self::assertFalse($heading->columns, 'A grandchild span does not make the root a column container.');

        // A dropped unknown slot does not count.
        $dropped = $this->bind(new FormDocument([
            new Section('main', null, [new FieldSlot('k2xq7vn4mz3t', null, $span('4')), new FieldSlot($this->name)]),
            new FieldSlot('b7xk2mq4nz3p', null, $span('6')),
        ]), new BindData());
        self::assertFalse($dropped->nodes[0]->columns);
        self::assertFalse($dropped->columns);
        self::assertCount(1, $dropped->nodes);

        // A root field with a span puts the root in column mode.
        $root = $this->bind(new FormDocument([new FieldSlot($this->name, null, $span('4')), new Divider()]), new BindData());
        self::assertTrue($root->columns);
        self::assertSame('4', $root->nodes[0]->span);
        self::assertNull($root->nodes[1]->span);
    }
}
