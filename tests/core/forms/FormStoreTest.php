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
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\StoredForm;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Tests\Core\Schema\ProjectFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/../schema/ProjectFixture.php';

/**
 * T-FORM-6 (effective), T-FORM-7 (regenerate), and T-FORM-10 (one key
 * scope for forms, tables, fields, and relations). Synthetic data only.
 */
#[CoversClass(FormStore::class)]
#[CoversClass(StoredForm::class)]
final class FormStoreTest extends TestCase
{
    use ProjectFixture;

    private const SEED = 'tiffindb-form-store-test-1';

    private string $logFile = '';
    private string|false|null $previousLog = null;

    protected function setUp(): void
    {
        $this->setUpProject(self::SEED);
        $this->logFile = $this->temp->path('php-error.log');
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== null) {
            ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        }
        $this->tearDownProject();
    }

    /** PHPUnit captures error_log() from the start of the test body on; this sends it to a file instead. */
    private function captureLog(): void
    {
        $this->previousLog = ini_set('error_log', $this->logFile);
    }

    private function store(?Keys $keys = null): FormStore
    {
        return new FormStore($this->db, $keys ?? new Keys(new SeededRandom(self::SEED . '-forms')), $this->clock, new FormParser($this->types));
    }

    /** An editor whose structural changes regenerate forms through $store, as Core\App will wire it. */
    private function formEditor(?FormStore $store = null, ?Keys $keys = null): SchemaEditor
    {
        $store ??= $this->store();

        return new SchemaEditor(
            $this->db,
            $this->folder,
            $this->types,
            $keys ?? $this->keys,
            $this->clock,
            $this->snapshots,
            self::USER_KEY,
            function (SnapshotInfo $info): void {
                $this->taken[] = $info;
            },
            fn (Database $db, array $tableKeys) => $store->regenerate(new Schema($db, $this->types), $tableKeys),
        );
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    /** @return list<array<string, mixed>> */
    private function formRows(): array
    {
        return $this->db->all('SELECT * FROM "_tiffin_forms" ORDER BY "table_key", "key"');
    }

    private function generatedText(string $tableKey): string
    {
        return (new FormSerializer())->serialize((new FormGenerator())->generate($this->schema(), $tableKey));
    }

    private function insertForm(string $tableKey, string $html, int $generated, int $format = 1): void
    {
        $this->db->write(static fn (Database $db) => $db->run(
            'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at")'
            . ' VALUES (\'f7qk2mz4xn3w\', ?, \'Edited form\', ?, ?, \'\', 1, ?, \'2026-11-12T03:15:00Z\', \'2026-11-12T03:15:00Z\')',
            [$tableKey, $format, $html, $generated],
        ));
    }

    // ------------------------------------------------------------ T-FORM-6

    public function testEffectiveUsesAValidStoredForm(): void
    {
        $this->captureLog();
        $table = $this->editor()->createTable('Items', 'item', true);
        $name = $this->schema()->fields($table->key)[0]->key;
        $this->insertForm($table->key, '<div data-tiffin-form="1"><p>Hello</p><div data-tiffin-field="' . $name . '" data-tiffin-label="Title"/></div>', 0);
        $before = $this->dump();

        $document = $this->store()->effective($this->schema(), $table->key);

        self::assertEquals(new FormDocument([new Paragraph([new TextRun('Hello')]), new FieldSlot($name, 'Title')]), $document);
        self::assertSame($before, $this->dump());
        self::assertSame('', $this->log());
        $stored = $this->store()->defaultFor($table->key);
        self::assertInstanceOf(StoredForm::class, $stored);
        self::assertSame(['f7qk2mz4xn3w', $table->key, 'Edited form', 1, '', true, false], [
            $stored->key, $stored->tableKey, $stored->name, $stored->format, $stored->css, $stored->isDefault, $stored->generated,
        ]);
    }

    public function testEffectiveGeneratesWhenMissingRejectedOrAnotherFormat(): void
    {
        $this->captureLog();
        $table = $this->editor()->createTable('Items', 'item', true);
        $generated = (new FormGenerator())->generate($this->schema(), $table->key);
        $store = $this->store();

        $before = $this->dump();
        self::assertEquals($generated, $store->effective($this->schema(), $table->key));
        self::assertSame($before, $this->dump());
        self::assertSame('', $this->log());

        $this->insertForm($table->key, '<div data-tiffin-form="1"><script>alert("SYNTHETIC-CONTENT")</script></div>', 0);
        $before = $this->dump();
        self::assertEquals($generated, $store->effective($this->schema(), $table->key));
        self::assertSame($before, $this->dump());
        $log = $this->log();
        self::assertStringContainsString('reason=element', $log);
        self::assertStringContainsString('table_key=' . $table->key, $log);
        self::assertStringNotContainsString('SYNTHETIC-CONTENT', $log);
        self::assertStringNotContainsString('script', $log);

        $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "format" = 2, "html" = \'<div data-tiffin-form="1"></div>\''));
        $before = $this->dump();
        self::assertEquals($generated, $store->effective($this->schema(), $table->key));
        self::assertSame($before, $this->dump());
        self::assertStringContainsString('reason=format-version', $this->log());
    }

    public function testEffectiveIgnoresFormCss(): void
    {
        $table = $this->editor()->createTable('Items', 'item', true);
        $this->insertForm($table->key, '<div data-tiffin-form="1"></div>', 0);
        $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "css" = \'body { display: none }\''));
        self::assertEquals(new FormDocument([]), $this->store()->effective($this->schema(), $table->key));
    }

    // ------------------------------------------------------------ T-FORM-7

    public function testRegenerateInsertsAMissingDefaultForm(): void
    {
        $table = $this->editor()->createTable('Items', 'item', true);
        $this->clock->advance(60);
        $this->db->write(fn (Database $db) => $this->store()->regenerate($this->schema(), [$table->key, 'b7xk2mq4nz3p']));

        $rows = $this->formRows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertTrue(Keys::isValid($row['key']));
        self::assertSame([$table->key, 'Standard form', 1, $this->generatedText($table->key), '', 1, 1, '2026-11-12T03:16:00Z', '2026-11-12T03:16:00Z'], [
            $row['table_key'], $row['name'], $row['format'], $row['html'], $row['css'], $row['is_default'], $row['generated'], $row['created_at'], $row['updated_at'],
        ]);
    }

    public function testRegenerateRefusesToRunOutsideATransaction(): void
    {
        $table = $this->editor()->createTable('Items', 'item', true);
        $this->expectException(\LogicException::class);
        $this->store()->regenerate($this->schema(), [$table->key]);
    }

    public function testGeneratedFormFollowsEveryFieldChange(): void
    {
        $editor = $this->formEditor();
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', true);
        $check = function (string $tableKey): void {
            $row = $this->db->one('SELECT "html", "generated" FROM "_tiffin_forms" WHERE "table_key" = ?', [$tableKey]);
            self::assertIsArray($row);
            self::assertSame($this->generatedText($tableKey), $row['html']);
            self::assertSame(1, $row['generated']);
        };
        $check($items->key);
        $check($rooms->key);

        $notes = $editor->addField($items->key, 'Notes', 'text', [], false, '');
        $check($items->key);
        self::assertStringContainsString($notes->key, $this->stored('html', $items->key));

        $this->clock->advance(60);
        $editor->moveField($notes->key, -1);
        $check($items->key);
        self::assertSame('2026-11-12T03:16:00Z', $this->stored('updated_at', $items->key));

        $links = $editor->addRelation($items->key, 'Places', $rooms->key, Cardinality::Many, Cardinality::Many, 'Things', false, '');
        $check($items->key);
        $check($rooms->key);
        self::assertStringContainsString('<legend>Places</legend>', $this->stored('html', $items->key));
        $editor->updateField($links->key, 'Locations', ['links' => 'many'], false, '', 0);
        $check($items->key);
        self::assertStringContainsString('<legend>Locations</legend>', $this->stored('html', $items->key));

        $editor->changeType($notes->key, 'long_text', [], 0);
        $check($items->key);
        self::assertStringContainsString('<div data-tiffin-field="' . $notes->key . '" data-tiffin-span="full"></div>', $this->stored('html', $items->key));

        $editor->deleteField($notes->key);
        $check($items->key);
        self::assertStringNotContainsString($notes->key, $this->stored('html', $items->key));

        $editor->deleteField($links->key);
        $check($items->key);
        $check($rooms->key);

        $editor->deleteTable($rooms->key);
        self::assertSame([$items->key], array_column($this->formRows(), 'table_key'));
    }

    public function testAnUnchangedGeneratedFormIsNotRewritten(): void
    {
        $editor = $this->formEditor();
        $items = $editor->createTable('Items', 'item', true);
        $this->clock->advance(60);
        $this->db->write(fn (Database $db) => $this->store()->regenerate($this->schema(), [$items->key]));
        self::assertSame('2026-11-12T03:15:00Z', $this->stored('updated_at', $items->key));
    }

    public function testAnEditedFormGainsAndLosesSlotsOnly(): void
    {
        $editor = $this->formEditor();
        $items = $editor->createTable('Items', 'item', true);
        $name = $this->schema()->fields($items->key)[0]->key;
        $old = $editor->addField($items->key, 'Old', 'text', [], false, '');
        $edited = '<div data-tiffin-form="1">' . "\n"
            . '  <!-- edited by a person -->' . "\n"
            . '  <h2>Item card</h2>' . "\n"
            . '  <fieldset data-tiffin-section="main" data-tiffin-span="full">' . "\n"
            . '    <legend>Main</legend>' . "\n"
            . '    <div data-tiffin-field="' . $old->key . '"></div>' . "\n"
            . '    <div data-tiffin-field="' . $name . '" data-tiffin-label="Title"></div>' . "\n"
            . '  </fieldset>' . "\n"
            . '  <fieldset><legend>Second</legend></fieldset>' . "\n"
            . '</div>' . "\n";
        $this->db->write(fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "table_key" = ?', [$edited, $items->key]));

        $added = $editor->addField($items->key, 'Added', 'number', [], false, '');
        $editor->deleteField($old->key);

        $html = $this->stored('html', $items->key);
        self::assertSame(0, $this->db->value('SELECT "generated" FROM "_tiffin_forms" WHERE "table_key" = ?', [$items->key]));
        self::assertEquals(new FormDocument([
            new Heading(2, [new TextRun('Item card')]),
            new Section('main', 'Main', [new FieldSlot($name, 'Title'), new FieldSlot($added->key)], new Placement(null, null, 'full')),
            new Section(null, 'Second', []),
        ]), (new FormParser($this->types))->parse($html));

        // Moving a field changes nothing in an edited form: its text stays as stored.
        $editor->moveField($added->key, -1);
        self::assertSame($html, $this->stored('html', $items->key));
    }

    public function testAnEditedFormWithoutSectionsGainsSlotsAtTheEnd(): void
    {
        $editor = $this->formEditor();
        $items = $editor->createTable('Items', 'item', true);
        $name = $this->schema()->fields($items->key)[0]->key;
        $this->db->write(fn (Database $db) => $db->run(
            'UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0',
            ['<div data-tiffin-form="1"><p>Note</p><div data-tiffin-field="' . $name . '"></div></div>'],
        ));
        $added = $editor->addField($items->key, 'Added', 'date', [], false, '');
        self::assertEquals(
            new FormDocument([new Paragraph([new TextRun('Note')]), new FieldSlot($name), new FieldSlot($added->key)]),
            (new FormParser($this->types))->parse($this->stored('html', $items->key)),
        );
    }

    public function testARejectedEditedFormIsLeftAlone(): void
    {
        $editor = $this->formEditor();
        $items = $editor->createTable('Items', 'item', true);
        $bad = '<div data-tiffin-form="1"><p onclick="x()">Hi</p></div>';
        $this->db->write(fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0', [$bad]));
        $editor->addField($items->key, 'Added', 'text', [], false, '');
        self::assertSame($bad, $this->stored('html', $items->key));

        // A default form in a format this version does not know is left alone too, even when marked generated.
        $this->db->write(fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "format" = 2, "generated" = 1'));
        $editor->addField($items->key, 'Another', 'text', [], false, '');
        self::assertSame($bad, $this->stored('html', $items->key));
    }

    private function stored(string $column, string $tableKey): string
    {
        $value = $this->db->value('SELECT "' . ($column === 'html' ? 'html' : 'updated_at') . '" FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1', [$tableKey]);
        self::assertIsString($value);

        return $value;
    }

    // ------------------------------------------------------------ T-FORM-10

    public function testANewFieldKeyNeverEqualsAFormKey(): void
    {
        $table = $this->editor()->createTable('Items', 'item', false);
        // The form store and the editor draw keys from the same seed, so their first candidates collide.
        $seed = self::SEED . '-collide-1';
        $this->db->write(fn (Database $db) => $this->store(new Keys(new SeededRandom($seed)))->regenerate($this->schema(), [$table->key]));
        $formKey = $this->db->value('SELECT "key" FROM "_tiffin_forms"');
        $sequence = new Keys(new SeededRandom($seed));
        self::assertSame($sequence->generate(), $formKey);

        $field = $this->formEditor(null, new Keys(new SeededRandom($seed)))->addField($table->key, 'Notes', 'text', [], false, '');
        self::assertNotSame($formKey, $field->key);
        self::assertSame($sequence->generate(), $field->key);
    }

    public function testANewFormKeyNeverEqualsATableFieldOrRelationKey(): void
    {
        $seed = self::SEED . '-collide-2';
        $editor = new SchemaEditor($this->db, $this->folder, $this->types, new Keys(new SeededRandom($seed)), $this->clock, $this->snapshots, self::USER_KEY, static function (SnapshotInfo $info): void {
        });
        $items = $editor->createTable('Items', 'item', true);
        $rooms = $editor->createTable('Rooms', 'room', false);
        $relationField = $editor->addRelation($items->key, 'Room', $rooms->key, Cardinality::One, Cardinality::Many, 'Items', false, '');
        $used = [$items->key, $this->schema()->fields($items->key)[0]->key, $rooms->key, $relationField->options['relation'], $relationField->key];
        $used[] = $this->schema()->fields($rooms->key)[0]->key;
        $sequence = new Keys(new SeededRandom($seed));
        $expected = [];
        for ($i = 0; $i < 6; $i++) {
            $expected[] = $sequence->generate();
        }
        sort($used);
        sort($expected);
        self::assertSame($expected, $used);

        $this->db->write(fn (Database $db) => $this->store(new Keys(new SeededRandom($seed)))->regenerate($this->schema(), [$items->key, $rooms->key]));
        $formKeys = array_column($this->formRows(), 'key');
        self::assertCount(2, $formKeys);
        self::assertSame([], array_intersect($formKeys, $used));
        $next = [$sequence->generate(), $sequence->generate()];
        sort($next);
        sort($formKeys);
        self::assertSame($next, $formKeys);
    }
}
