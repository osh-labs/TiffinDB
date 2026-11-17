<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Schema\SchemaEditor;

require_once __DIR__ . '/ProjectFixture.php';

/** T-SCH-14: every change updates meta updated_at and updated_by, in the same transaction. */
#[CoversClass(SchemaEditor::class)]
#[CoversClass(ProjectFile::class)]
final class MetaTouchTest extends TestCase
{
    use ProjectFixture;

    protected function setUp(): void
    {
        $this->setUpProject('tiffindb-meta-touch-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    /** @return array{string, string} */
    private function meta(): array
    {
        return [
            (string) $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_at\''),
            (string) $this->db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'updated_by\''),
        ];
    }

    public function testEveryChangeUpdatesTheMeta(): void
    {
        $users = [self::USER_KEY, self::OTHER_USER_KEY];
        $step = 0;
        $expectChange = function (callable $change) use (&$step, $users): mixed {
            $step++;
            $this->clock->advance(60);
            $user = $users[$step % 2];
            $result = $change($this->editor($user));
            self::assertSame([$this->clock->now()->format('Y-m-d\TH:i:s\Z'), $user], $this->meta(), 'step ' . $step);

            return $result;
        };

        $items = $expectChange(fn (SchemaEditor $e) => $e->createTable('Items', 'item', true));
        $rooms = $expectChange(fn (SchemaEditor $e) => $e->createTable('Rooms', 'room', false));
        $expectChange(fn (SchemaEditor $e) => $e->updateTable($rooms->key, 'Places', 'place', null));
        $stars = $expectChange(fn (SchemaEditor $e) => $e->addField($items->key, 'Stars', 'rating', [], false, ''));
        $link = $expectChange(fn (SchemaEditor $e) => $e->addRelation($items->key, 'Place', $rooms->key, Cardinality::Many, Cardinality::Many, 'Items', false, ''));
        $expectChange(fn (SchemaEditor $e) => $e->updateField($stars->key, 'Score', ['max' => 7], false, '', false));
        $expectChange(fn (SchemaEditor $e) => $e->updateField($link->key, 'Place', ['links' => 'one'], false, '', false));
        $expectChange(fn (SchemaEditor $e) => $e->changeType($stars->key, 'number', [], false));
        $expectChange(fn (SchemaEditor $e) => $e->moveField($stars->key, -1));
        $expectChange(fn (SchemaEditor $e) => $e->deleteField($link->key));
        $expectChange(fn (SchemaEditor $e) => $e->deleteTable($rooms->key));
    }

    public function testFailedAndEmptyChangesLeaveTheMeta(): void
    {
        $items = $this->editor()->createTable('Items', 'item', true);
        $before = $this->meta();
        $this->clock->advance(60);
        self::problems(fn () => $this->editor(self::OTHER_USER_KEY)->createTable('items', 'item', true));
        self::problems(fn () => $this->editor(self::OTHER_USER_KEY)->addField($items->key, '', 'text', [], false, ''));
        $this->editor(self::OTHER_USER_KEY)->moveField($this->schema()->fields($items->key)[0]->key, -1);
        self::assertSame($before, $this->meta());
    }
}
