<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\SchemaOperations;
use TiffinDB\Core\Error\ConfirmationRequired;
use TiffinDB\Core\Schema\Identifier;
use TiffinDB\Core\Schema\SchemaEditor;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-APP-80 (P82, M2 spec part 1 section 7.5): a change that clears values
 * is bound to the number of values the person was shown. With that number
 * accepted it goes ahead; when more values would be cleared (a record
 * changed between the preview and the change, written here on a second
 * connection to the project file) it throws ConfirmationRequired with the
 * new counts and changes nothing. Synthetic data only.
 */
#[CoversClass(SchemaOperations::class)]
#[CoversClass(SchemaEditor::class)]
final class ConfirmationCountTest extends AppTestCase
{
    private string $project;
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->ops->projects()->create($this->admin, 'Example Garden', 'Plants')->key;
        $this->table = $this->ops->schema()->tables($this->admin, $this->project)[0]->table->key;
    }

    public function testUpdateFieldWithTheShownCountSucceeds(): void
    {
        $stars = $this->rating([1, 4, 5, null]);
        $shown = $this->ops->schema()->previewFieldUpdate($this->admin, $this->project, $this->table, $stars, ['max' => 3]);
        self::assertSame([2, 2], [$shown->records, $shown->values]);

        $updated = $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', $shown->values);

        self::assertSame(['max' => 3], $updated->options);
        self::assertSame([1, null, null, null], $this->column($stars));
    }

    public function testUpdateFieldWithMoreToClearThanAcceptedAsksAgainAndChangesNothing(): void
    {
        $stars = $this->rating([1, 4, 5]);
        $shown = $this->ops->schema()->previewFieldUpdate($this->admin, $this->project, $this->table, $stars, ['max' => 3]);
        self::assertSame(2, $shown->values);
        // Someone else saves a record between the confirmation page and the post.
        $this->fx->insertRecord($this->project, $this->table, [$stars => 5]);
        $before = $this->state();
        $audits = $this->auditCount();

        $needed = $this->confirmation(fn () => $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', $shown->values));

        self::assertSame([3, 3], [$needed->records, $needed->values]);
        self::assertSame($before, $this->state());
        self::assertSame($audits, $this->auditCount());

        // Accepting the new count goes ahead.
        $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', $needed->values);
        self::assertSame([1, null, null, null], $this->column($stars));
    }

    public function testChangeFieldTypeWithTheShownCountSucceeds(): void
    {
        $notes = $this->text(['12', 'twelve', '3', 'n/a']);
        $shown = $this->ops->schema()->previewTypeChange($this->admin, $this->project, $this->table, $notes, 'number', []);
        self::assertSame([2, 2], [$shown->records, $shown->values]);

        $impact = $this->ops->schema()->changeFieldType($this->admin, $this->project, $this->table, $notes, 'number', [], $shown->values);

        self::assertSame(2, $impact->values);
        self::assertSame([12, null, 3, null], $this->column($notes));
        self::assertSame('number', $this->ops->schema()->design($this->admin, $this->project, $this->table)->fields[1]->field->type);
    }

    public function testChangeFieldTypeWithMoreToClearThanAcceptedAsksAgainAndChangesNothing(): void
    {
        $notes = $this->text(['12', 'twelve']);
        $shown = $this->ops->schema()->previewTypeChange($this->admin, $this->project, $this->table, $notes, 'number', []);
        self::assertSame(1, $shown->values);
        $this->fx->insertRecord($this->project, $this->table, [$notes => 'a dozen']);
        $this->fx->insertRecord($this->project, $this->table, [$notes => 'many']);
        $before = $this->state();
        $audits = $this->auditCount();

        $needed = $this->confirmation(fn () => $this->ops->schema()->changeFieldType($this->admin, $this->project, $this->table, $notes, 'number', [], $shown->values));

        self::assertSame([3, 3], [$needed->records, $needed->values]);
        self::assertSame($before, $this->state());
        self::assertSame($audits, $this->auditCount());
        self::assertSame('text', $this->ops->schema()->design($this->admin, $this->project, $this->table)->fields[1]->field->type);
    }

    public function testNothingAcceptedMeansNoValueMayBeCleared(): void
    {
        $stars = $this->rating([5]);
        $notes = $this->text(['twelve']);

        self::assertSame(1, $this->confirmation(fn () => $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', 0))->values);
        self::assertSame(1, $this->confirmation(fn () => $this->ops->schema()->changeFieldType($this->admin, $this->project, $this->table, $notes, 'number', [], 0))->values);
        // A negative count accepts nothing either.
        self::assertSame(1, $this->confirmation(fn () => $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', -1))->values);
        self::assertSame(1, $this->confirmation(fn () => $this->ops->schema()->changeFieldType($this->admin, $this->project, $this->table, $notes, 'number', [], -5))->values);
        self::assertSame([5, null], $this->column($stars));
        self::assertSame([null, 'twelve'], $this->column($notes));
    }

    public function testFewerValuesThanAcceptedStillGoAhead(): void
    {
        // A record changed the other way (a value removed) needs no new confirmation.
        $stars = $this->rating([4, 5]);
        $shown = $this->ops->schema()->previewFieldUpdate($this->admin, $this->project, $this->table, $stars, ['max' => 3])->values;
        $this->fx->projectDb($this->project)->run('UPDATE ' . Identifier::table($this->table) . ' SET ' . Identifier::column($stars) . ' = NULL WHERE "id" = 1');

        $this->ops->schema()->updateField($this->admin, $this->project, $this->table, $stars, 'Stars', ['max' => 3], false, '', $shown);

        self::assertSame([null, null], $this->column($stars));
    }

    /** @param list<?int> $values */
    private function rating(array $values): string
    {
        $key = $this->ops->schema()->addField($this->admin, $this->project, $this->table, 'Stars', 'rating', ['max' => 5], false, '')->key;
        foreach ($values as $value) {
            $this->fx->insertRecord($this->project, $this->table, [$key => $value]);
        }

        return $key;
    }

    /** @param list<?string> $values */
    private function text(array $values): string
    {
        $key = $this->ops->schema()->addField($this->admin, $this->project, $this->table, 'Notes', 'text', [], false, '')->key;
        foreach ($values as $value) {
            $this->fx->insertRecord($this->project, $this->table, [$key => $value]);
        }

        return $key;
    }

    private function confirmation(callable $work): ConfirmationRequired
    {
        try {
            $work();
        } catch (ConfirmationRequired $needed) {
            return $needed;
        }
        self::fail('ConfirmationRequired was expected.');
    }

    /** @return list<mixed> one field's stored values, by record id */
    private function column(string $fieldKey): array
    {
        return array_column(
            $this->fx->projectDb($this->project)->all('SELECT ' . Identifier::column($fieldKey) . ' AS v FROM ' . Identifier::table($this->table) . ' ORDER BY "id"'),
            'v',
        );
    }

    /** @return array<string, list<array<string, mixed>>> every record row and every field definition */
    private function state(): array
    {
        $db = $this->fx->projectDb($this->project);

        return [
            'records' => $db->all('SELECT * FROM ' . Identifier::table($this->table) . ' ORDER BY "id"'),
            'fields' => $db->all('SELECT * FROM "_tiffin_fields" ORDER BY "key"'),
        ];
    }

    private function auditCount(): int
    {
        return (int) $this->fx->openSystem()->db()->one('SELECT COUNT(*) AS n FROM audit_log')['n'];
    }
}
