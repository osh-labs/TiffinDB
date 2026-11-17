<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Type\Checked;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Support\SeededRandom;

/** T-TYP-6 and the registry, Checked, and per-type constants of M1 spec sections 7.5, 8.1, and 8.2. */
#[CoversClass(FieldTypes::class)]
#[CoversClass(Checked::class)]
final class FieldTypesTest extends TestCase
{
    private const IDS = [
        'text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'checkbox',
        'choice', 'rating', 'url', 'email', 'phone', 'attachment', 'relation', 'auto_number',
    ];

    public function testStandardHasTheSeventeenTypesInOrder(): void
    {
        $types = FieldTypes::standard(new Keys(new SeededRandom('field-types')));
        self::assertSame(self::IDS, $types->ids());
        foreach (self::IDS as $id) {
            self::assertTrue($types->has($id));
            self::assertSame($id, $types->get($id)->id());
        }
        self::assertFalse($types->has('Text'));
        self::assertFalse($types->has(''));
        self::assertFalse($types->has('formula'));
    }

    public function testStandardWorksWithoutKeys(): void
    {
        self::assertSame(self::IDS, FieldTypes::standard()->ids());
    }

    public function testUnknownTypeIsAnInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FieldTypes::standard()->get('formula');
    }

    public function testClassNamesFollowTheIds(): void
    {
        $types = FieldTypes::standard();
        foreach (self::IDS as $id) {
            $class = 'TiffinDB\\Core\\Schema\\Type\\' . str_replace('_', '', ucwords($id, '_')) . 'Type';
            self::assertInstanceOf($class, $types->get($id));
            self::assertTrue((new \ReflectionClass($class))->isFinal(), $class . ' is final.');
        }
    }

    /** Column definitions as `docs/formats/tiffin.md` section 6. */
    public function testColumnDefinitions(): void
    {
        $expected = [
            'text' => 'TEXT', 'long_text' => 'TEXT', 'number' => 'NUMERIC', 'currency' => 'INTEGER',
            'date' => 'TEXT', 'time' => 'TEXT', 'date_time' => 'TEXT', 'duration' => 'INTEGER',
            'checkbox' => 'INTEGER NOT NULL DEFAULT 0', 'choice' => 'TEXT', 'rating' => 'INTEGER',
            'url' => 'TEXT', 'email' => 'TEXT', 'phone' => 'TEXT', 'attachment' => null, 'relation' => null,
            'auto_number' => 'INTEGER',
        ];
        $types = FieldTypes::standard();
        foreach ($expected as $id => $definition) {
            self::assertSame($definition, $types->get($id)->columnDefinition(), $id);
        }
    }

    public function testCanBeTitleAndIsEditable(): void
    {
        $titles = ['text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'choice', 'email', 'phone', 'url', 'auto_number'];
        $types = FieldTypes::standard();
        foreach (self::IDS as $id) {
            self::assertSame(in_array($id, $titles, true), $types->get($id)->canBeTitle(), $id);
            self::assertSame($id !== 'auto_number', $types->get($id)->isEditable(), $id);
        }
    }

    /** T-TYP-6: all 17 x 17 pairs against section 8.4. */
    public function testCanConvertMatchesTheMatrix(): void
    {
        $set = ['text', 'long_text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'checkbox', 'choice', 'rating', 'url', 'email', 'phone'];
        $types = FieldTypes::standard();
        $pairs = 0;
        $allowed = 0;
        foreach (self::IDS as $from) {
            foreach (self::IDS as $to) {
                $expected = $from !== $to && (
                    (in_array($from, $set, true) && in_array($to, $set, true))
                    || ($from === 'auto_number' && in_array($to, ['number', 'text'], true))
                );
                self::assertSame($expected, $types->canConvert($from, $to), $from . ' to ' . $to);
                $pairs++;
                $allowed += $expected ? 1 : 0;
            }
        }
        self::assertSame(289, $pairs);
        self::assertSame(14 * 13 + 2, $allowed);
        // Spot checks written out by hand.
        self::assertTrue($types->canConvert('text', 'number'));
        self::assertTrue($types->canConvert('auto_number', 'text'));
        self::assertFalse($types->canConvert('auto_number', 'currency'));
        self::assertFalse($types->canConvert('number', 'auto_number'));
        self::assertFalse($types->canConvert('text', 'relation'));
        self::assertFalse($types->canConvert('attachment', 'text'));
        self::assertFalse($types->canConvert('text', 'text'));
        self::assertFalse($types->canConvert('text', 'formula'));
        self::assertFalse($types->canConvert('formula', 'text'));
    }

    public function testCheckedOk(): void
    {
        $ok = Checked::ok('x');
        self::assertTrue($ok->isOk());
        self::assertSame('x', $ok->value());
        self::assertNull($ok->problemKey());
        self::assertSame([], $ok->problemParams());
        self::assertNull(Checked::ok(null)->value());
    }

    public function testCheckedProblem(): void
    {
        $problem = Checked::problem('problem.rating.invalid', ['max' => 5]);
        self::assertFalse($problem->isOk());
        self::assertSame('problem.rating.invalid', $problem->problemKey());
        self::assertSame(['max' => 5], $problem->problemParams());
        $this->expectException(\LogicException::class);
        $problem->value();
    }

    public function testCheckedProblemNeedsAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Checked::problem('');
    }
}
