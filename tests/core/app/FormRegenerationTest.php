<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\Context;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Error\Unavailable;
use TiffinDB\Core\Schema\Cardinality;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-77: schema changes through SchemaOperations regenerate forms in the same transaction. */
#[CoversClass(Context::class)]
#[CoversClass(Operations::class)]
final class FormRegenerationTest extends AppTestCase
{
    /** @return list<array<string, mixed>> */
    private function forms(string $projectKey): array
    {
        return $this->fx->projectDb($projectKey)->all('SELECT "table_key", "html", "generated", "is_default" FROM "_tiffin_forms" ORDER BY "table_key"');
    }

    public function testTablesAndFieldsGetStoredDefaultForms(): void
    {
        $schema = $this->ops->schema();
        $project = $this->ops->projects()->create($this->admin, 'Example Household', 'Items')->key;
        $items = $schema->tables($this->admin, $project)[0]->table->key;
        $name = $schema->design($this->admin, $project, $items)->fields[0]->field->key;

        $forms = $this->forms($project);
        self::assertCount(1, $forms, 'The first table gets a stored default form.');
        self::assertSame([$items, 1, 1], [$forms[0]['table_key'], $forms[0]['generated'], $forms[0]['is_default']]);
        self::assertStringContainsString('data-tiffin-field="' . $name . '"', (string) $forms[0]['html']);

        $rooms = $schema->createTable($this->admin, $project, 'Rooms', 'room', false)->key;
        $expected = [$items, $rooms];
        sort($expected);
        self::assertSame($expected, array_column($this->forms($project), 'table_key'), 'A table created later gets one too.');

        $count = $schema->addField($this->admin, $project, $items, 'Count', 'number', [], false, '');
        $html = $this->formHtml($project, $items);
        self::assertStringContainsString('data-tiffin-field="' . $count->key . '"', $html);

        $link = $schema->addRelationField($this->admin, $project, $items, 'Located in', $rooms, Cardinality::Many, Cardinality::Many, 'Holds', false, '');
        self::assertStringContainsString('data-tiffin-field="' . $link->key . '"', $this->formHtml($project, $items));
        self::assertStringContainsString('Holds', $this->formHtml($project, $rooms), 'Both tables of a relation are regenerated.');

        $schema->deleteField($this->admin, $project, $items, $count->key);
        self::assertStringNotContainsString($count->key, $this->formHtml($project, $items));

        $schema->deleteTable($this->admin, $project, $rooms);
        self::assertSame([$items], array_column($this->forms($project), 'table_key'));
        self::assertStringNotContainsString($link->key, $this->formHtml($project, $items));
    }

    public function testAFailingRegenerationRollsTheSchemaChangeBack(): void
    {
        $schema = $this->ops->schema();
        $project = $this->ops->projects()->create($this->admin, 'Example Household', 'Items')->key;
        $items = $schema->tables($this->admin, $project)[0]->table->key;
        $this->fx->projectDb($project)->run('UPDATE "_tiffin_forms" SET "generated" = 7 WHERE "table_key" = ?', [$items]);
        $before = $schema->design($this->admin, $project, $items)->fields;

        $this->assertThrows(Unavailable::class, fn () => $schema->addField($this->admin, $project, $items, 'Count', 'number', [], false, ''));

        self::assertEquals($before, $schema->design($this->admin, $project, $items)->fields, 'The field was not added.');
        self::assertNotContains('schema.field_created', $this->auditActions());
    }

    public function testOperationsStillHasSixRequiredConstructorArguments(): void
    {
        $constructor = new \ReflectionMethod(Operations::class, '__construct');
        self::assertSame(6, $constructor->getNumberOfRequiredParameters());
        self::assertSame(7, $constructor->getNumberOfParameters());
    }

    private function formHtml(string $projectKey, string $tableKey): string
    {
        foreach ($this->forms($projectKey) as $form) {
            if ($form['table_key'] === $tableKey) {
                return (string) $form['html'];
            }
        }
        self::fail('No stored form for the table.');
    }
}
