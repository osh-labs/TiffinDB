<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormGenerator;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Core\Schema\ProjectFixture;

require_once __DIR__ . '/../schema/ProjectFixture.php';
require_once __DIR__ . '/FormParserTest.php';

/**
 * T-FORM-5: the generated layout of `docs/formats/tiffin.md` section 12.3
 * (M2 spec section 5.4), and the second half of T-FORM-3: the serialized
 * generated example equals the document's example text.
 */
#[CoversClass(FormGenerator::class)]
final class FormGeneratorTest extends TestCase
{
    use ProjectFixture;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-form-generator-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    public function testEveryTypeOneLinkAndManyLinkRelationsAndAttachments(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $keys = [$this->schema()->fields($items->key)[0]->key];
        foreach ($this->types->ids() as $type) {
            if ($type === 'relation' || $type === 'text') {
                continue;
            }
            $options = $type === 'choice' ? ['choices' => ['Tool', 'Appliance']] : [];
            $keys[] = $editor->addField($items->key, 'Field ' . $type, $type, $options, false, '')->key;
        }
        $room = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $warranties = $editor->addRelation($items->key, 'Warranties', $rooms->key, Cardinality::Many, Cardinality::Many, 'Covered items', false, '')->key;
        $photos = $editor->addField($items->key, 'More photos', 'attachment', [], false, '')->key;
        // Field order follows positions: move the last attachment field before the relations.
        $editor->moveField($photos, -1);
        $editor->moveField($photos, -1);

        $schema = $this->schema();
        $byName = [];
        foreach ($schema->fields($items->key) as $field) {
            $byName[$field->type][] = $field->key;
        }
        $document = (new FormGenerator())->generate($schema, $items->key);

        $details = [];
        foreach ($schema->fields($items->key) as $field) {
            if ($field->type === 'attachment' || $field->key === $warranties) {
                continue;
            }
            $full = in_array($field->type, ['long_text', 'rating'], true) ? new Placement(null, null, 'full') : null;
            $details[] = new FieldSlot($field->key, null, $full);
        }
        self::assertEquals(new FormDocument([
            new Section('details', 'Details', $details),
            new Section('files', 'Files', [new FieldSlot($byName['attachment'][0]), new FieldSlot($photos)]),
            new Section('links', 'Warranties', [new FieldSlot($warranties)]),
        ]), $document);
        self::assertContains($room, array_map(static fn (FieldSlot $s): string => $s->fieldKey, $details));
        self::assertCount(16, $details);

        // The other side of each relation: one-link sides stay in Details, many-link sides get their sections.
        $other = (new FormGenerator())->generate($schema, $rooms->key);
        self::assertSame(['details', 'links', 'links'], array_map(static fn (Section $s): ?string => $s->role, $other->blocks));
        self::assertSame(['Items', 'Covered items'], array_map(static fn (Section $s): ?string => $s->legend, array_slice($other->blocks, 1)));
    }

    public function testATableWithoutFieldsGetsOneEmptySection(): void
    {
        $table = $this->editor()->createTable('Empty', 'entry', false);
        $document = (new FormGenerator())->generate($this->schema(), $table->key);
        self::assertEquals(new FormDocument([new Section('details', 'Details', [])]), $document);
        self::assertSame(
            '<div data-tiffin-form="1">' . "\n" . '  <fieldset data-tiffin-section="details">' . "\n" . '    <legend>Details</legend>' . "\n"
            . '  </fieldset>' . "\n" . '</div>' . "\n",
            (new FormSerializer())->serialize($document),
        );
    }

    public function testSelfRelationSides(): void
    {
        $editor = $this->editor();
        $people = $editor->createTable('Contacts', 'contact', true);
        $editor->addRelation($people->key, 'Manager', $people->key, Cardinality::One, Cardinality::Many, 'Reports', false, '');
        $document = (new FormGenerator())->generate($this->schema(), $people->key);
        self::assertCount(2, $document->blocks);
        self::assertCount(2, $document->blocks[0]->children);
        self::assertSame('Reports', $document->blocks[1]->legend);
    }

    public function testSerializedGeneratedExampleEqualsTheDocument(): void
    {
        $editor = $this->editor();
        $items = $editor->createTable('Items', 'item', true);
        $warrantyTable = $editor->createTable('Warranty cards', 'warranty card', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $name = $this->schema()->fields($items->key)[0]->key;
        $category = $editor->addField($items->key, 'Category', 'choice', ['choices' => ['Appliance', 'Tool']], false, '')->key;
        $room = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $value = $editor->addField($items->key, 'Value', 'currency', [], false, '')->key;
        $notes = $editor->addField($items->key, 'Notes', 'long_text', [], false, '')->key;
        $photos = $editor->addField($items->key, 'Photos', 'attachment', [], false, '')->key;
        $warranties = $editor->addRelation($items->key, 'Warranties', $warrantyTable->key, Cardinality::Many, Cardinality::One, 'Item', false, '')->key;

        $text = (new FormSerializer())->serialize((new FormGenerator())->generate($this->schema(), $items->key));
        $text = strtr($text, [
            $name => 'm4pz7k2qsw3n',
            $category => 'w2qn6kz7tb4x',
            $room => 'r5vk3pn7qx2m',
            $value => 'b7xk2mq4nz3p',
            $notes => 't6mq2zk4wn7x',
            $photos => 'p3zk7mq2xn4w',
            $warranties => 'k2xq7vn4mz3t',
        ]);
        $normalise = static fn (string $s): string => (string) preg_replace('/^ +/m', '', $s);
        self::assertSame($normalise(FormParserTest::documentExample()), $normalise($text));
        // The serializer's indentation is exactly the document's.
        self::assertSame(FormParserTest::documentExample(), $text);
    }
}
