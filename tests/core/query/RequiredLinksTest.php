<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\FieldDef;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-ATT-12, T-LNK-8: Records::requiredLinkProblems (P157) for required
 * relation and attachment fields. All data is synthetic.
 */
#[CoversClass(Records::class)]
final class RequiredLinksTest extends TestCase
{
    use RecordsFixture;

    private FieldDef $mustRoom;
    private FieldDef $mustFile;
    private FieldDef $optionalFile;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-required-links-test-1');
        $editor = $this->editor();
        $this->mustRoom = $editor->addRelation($this->items->key, 'Kept in', $this->rooms->key, Cardinality::Many, Cardinality::Many, 'Keeps', true, '');
        $this->mustFile = $editor->addField($this->items->key, 'Receipt', 'attachment', [], true, '');
        $this->optionalFile = $this->f['files'];
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /**
     * @param list<string> $keys
     * @return list<array{0: ?string, 1: string}>
     */
    private function required(int $item, array $keys): array
    {
        return array_map(
            static fn (Problem $p): array => [$p->field, $p->key],
            $this->records()->requiredLinkProblems($this->items->key, $item, $keys),
        );
    }

    // ------------------------------------------------------------ T-LNK-8

    public function testARequiredRelationNeedsALiveLink(): void
    {
        $item = $this->item();
        $hall = $this->room('Hall');
        $keys = [$this->mustRoom->key];
        self::assertSame([[$this->mustRoom->key, 'problem.link.required']], $this->required($item, $keys), 'No links.');

        $this->records()->addLink($this->mustRoom->key, $item, $hall, self::USER_KEY);
        self::assertSame([], $this->required($item, $keys), 'One live link.');

        $this->trashRecord($this->rooms, $hall);
        self::assertSame([[$this->mustRoom->key, 'problem.link.required']], $this->required($item, $keys), 'Only a trashed link.');
    }

    public function testARequiredRelationFromItsOtherSide(): void
    {
        $editor = $this->editor();
        $mustHold = $editor->addRelation($this->rooms->key, 'Holds', $this->items->key, Cardinality::Many, Cardinality::Many, 'Held by', true, '');
        $hall = $this->room('Hall');
        $item = $this->item();
        $problems = $this->records()->requiredLinkProblems($this->rooms->key, $hall, [$mustHold->key]);
        self::assertSame(['problem.link.required'], array_map(static fn (Problem $p): string => $p->key, $problems));
        $this->records()->addLink($mustHold->key, $hall, $item, self::USER_KEY);
        self::assertSame([], $this->records()->requiredLinkProblems($this->rooms->key, $hall, [$mustHold->key]));
    }

    // ------------------------------------------------------------ T-ATT-12

    public function testARequiredAttachmentFieldNeedsAFile(): void
    {
        $item = $this->item();
        $keys = [$this->mustFile->key, $this->optionalFile->key];
        self::assertSame([[$this->mustFile->key, 'problem.attachment.required']], $this->required($item, $keys), 'No file; the optional field is ignored.');

        $this->attach($item, 'receipt', 'receipt.pdf', $this->mustFile);
        self::assertSame([], $this->required($item, $keys), 'One file.');
    }

    public function testOnlyTheGivenRequiredKeysOfTheTableAreChecked(): void
    {
        $item = $this->item();
        self::assertSame([], $this->required($item, []));
        self::assertSame([], $this->required($item, [$this->optionalFile->key, $this->f['stored']->key, $this->f['text']->key, 'zzzzzzzzzzzz']));
        self::assertSame([[$this->mustFile->key, 'problem.attachment.required']], $this->required($item, [$this->mustFile->key]));

        // Both, in field order, whatever the order given.
        self::assertSame(
            [[$this->mustRoom->key, 'problem.link.required'], [$this->mustFile->key, 'problem.attachment.required']],
            $this->required($item, [$this->mustFile->key, $this->mustRoom->key]),
        );

        // A required field of another table is ignored.
        $editor = $this->editor();
        $roomFile = $editor->addField($this->rooms->key, 'Plan', 'attachment', [], true, '');
        self::assertSame([[$this->mustFile->key, 'problem.attachment.required']], $this->required($item, [$this->mustFile->key, $roomFile->key]));
    }

    public function testArgumentsAreChecked(): void
    {
        try {
            $this->records()->requiredLinkProblems($this->items->key, 0, []);
            self::fail('A bad id was accepted.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(NotFound::class);
        $this->records()->requiredLinkProblems('zzzzzzzzzzzz', 1, []);
    }
}
