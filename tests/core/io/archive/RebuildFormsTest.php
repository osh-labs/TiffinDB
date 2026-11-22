<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Io\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Forms\FormGenerator;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Io\Archive\ProjectRebuilder;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/ArchiveFixture.php';

/**
 * T-ARC-7 (and the T-APP-95 case at core level), as changed by M5 spec
 * section 7.3 (T-EDT-12, P197, P208): stored forms are parsed with the
 * default-deny parser and regenerated with no added fields. A form with
 * `<script>` is dropped and the generated layout takes its place; an
 * edited default form loses the slot of a field that no longer exists
 * (counted in forms_extended) and does not regain a required field it
 * hides, so the record form does not show that field and a record without
 * it is accepted (P118, P157); an edited non-default form is kept as it
 * is; a second default form, a form of a missing table, of another
 * format, or with a bad key is dropped.
 */
#[CoversClass(ProjectRebuilder::class)]
final class RebuildFormsTest extends TestCase
{
    use ArchiveFixture;

    private const EDITED = '<div data-tiffin-form="1"><fieldset data-tiffin-section="details"><legend>Room card</legend><p>Edited by hand.</p></fieldset></div>';
    /** The edited default form: EDITED with a slot for a field that does not exist. */
    private const EDITED_DEFAULT = '<div data-tiffin-form="1"><fieldset data-tiffin-section="details"><legend>Room card</legend><p>Edited by hand.</p>'
        . '<div data-tiffin-field="' . self::GONE_KEY . '"></div></fieldset></div>';
    private const GONE_KEY = 'qqqqqqqqqqqq';
    private const OTHER_KEY = 'eeeeeeeeeeee';
    private const SECOND_DEFAULT_KEY = 'zzzzzzzzzzzz';

    protected function setUp(): void
    {
        $this->setUpArchive('tiffindb-archive-forms-test-1');
        $this->buildSource();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchive();
    }

    public function testFormsAreCheckedAndRegenerated(): void
    {
        $roomsDefault = (string) $this->db->value('SELECT "key" FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1', [$this->rooms->key]);
        $itemsDefault = (string) $this->db->value('SELECT "key" FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1', [$this->items->key]);
        self::assertLessThan(self::SECOND_DEFAULT_KEY, $roomsDefault);
        $insert = 'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at") VALUES ';
        $now = "'" . self::START . "', '" . self::START . "'";
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'DROP INDEX _tiffin_forms_default',
            "UPDATE _tiffin_fields SET required = 1 WHERE key = '" . $this->roomName->key . "'",
            "UPDATE _tiffin_forms SET html = '<div data-tiffin-form=\"1\"><script>alert(1)</script></div>' WHERE key = '" . $itemsDefault . "'",
            "UPDATE _tiffin_forms SET html = '" . self::EDITED_DEFAULT . "', generated = 0, name = 'Room card' WHERE key = '" . $roomsDefault . "'",
            $insert . "('" . self::OTHER_KEY . "', '" . $this->rooms->key . "', 'Short card', 1, '" . self::EDITED . "', '', 0, 0, " . $now . ')',
            $insert . "('" . self::SECOND_DEFAULT_KEY . "', '" . $this->rooms->key . "', 'Second', 1, '" . self::EDITED . "', '', 1, 0, " . $now . ')',
            $insert . "('aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'Orphan', 1, '" . self::EDITED . "', '', 0, 0, " . $now . ')',
            $insert . "('cccccccccccc', '" . $this->rooms->key . "', 'Future', 2, '" . self::EDITED . "', '', 0, 0, " . $now . ')',
            $insert . "('BAD', '" . $this->rooms->key . "', 'Bad key', 1, '" . self::EDITED . "', '', 0, 0, " . $now . ')',
            $insert . "('dddddddddddd', '" . $this->rooms->key . "', 'Styled', 1, '<div data-tiffin-form=\"1\"><p style=\"x\">Hi</p></div>', '', 0, 0, " . $now . ')',
        );
        [$report, $db, $folder] = $this->import($this->zipFor($copy));

        self::assertSame(6, $report->formsDropped);
        self::assertSame(1, $report->formsExtended, 'The edited default form lost the slot of a field that no longer exists.');

        $forms = [];
        foreach ($db->all('SELECT "key", "table_key", "name", "html", "css", "is_default", "generated" FROM "_tiffin_forms" ORDER BY "key"') as $row) {
            $forms[$row['key']] = $row;
        }
        $schema = new Schema($db, $this->types);
        $generated = (new FormSerializer())->serialize((new FormGenerator())->generate($schema, $this->items->key));
        $itemsForms = array_values(array_filter($forms, fn (array $f): bool => $f['table_key'] === $this->items->key));
        self::assertCount(1, $itemsForms);
        self::assertNotSame($itemsDefault, $itemsForms[0]['key'], 'The dropped form is replaced by a new generated one.');
        self::assertSame($generated, $itemsForms[0]['html']);
        self::assertSame([1, 1], [$itemsForms[0]['is_default'], $itemsForms[0]['generated']]);

        $extended = $forms[$roomsDefault];
        self::assertSame(0, $extended['generated']);
        self::assertSame('Room card', $extended['name']);
        self::assertStringContainsString('<p>Edited by hand.</p>', $extended['html']);
        self::assertStringNotContainsString('data-tiffin-field="' . self::GONE_KEY . '"', $extended['html']);
        self::assertStringNotContainsString('data-tiffin-field="' . $this->roomName->key . '"', $extended['html'], 'A hidden field stays hidden.');
        self::assertSame(self::EDITED, $forms[self::OTHER_KEY]['html'], 'A non-default edited form is kept as it is.');
        self::assertSame(0, $forms[self::OTHER_KEY]['is_default']);
        foreach ([self::SECOND_DEFAULT_KEY, 'aaaaaaaaaaaa', 'cccccccccccc', 'BAD', 'dddddddddddd'] as $key) {
            self::assertArrayNotHasKey($key, $forms);
        }
        self::assertSame(1, json_decode((string) $db->value('SELECT "report" FROM "_tiffin_import_log"'), true)['archive']['forms_extended']);
        $db->close();

        $core = new CoreFixture();
        try {
            $core->system->db()->write(function () use ($core): void {
                $registry = new ProjectRegistry($core->system);
                $registry->activate($registry->add(self::NEW_KEY, 'Imported Project', $core->person('admin')->id, CoreFixture::START)->id);
            });
            mkdir(dirname($core->projectFolder(self::NEW_KEY)), 0750, true);
            self::assertTrue(rename($folder, $core->projectFolder(self::NEW_KEY)));
            $records = $core->operations()->records();
            $admin = $core->actor('admin');
            $screen = $records->blank($admin, self::NEW_KEY, $this->rooms->key, ListRequest::none());
            self::assertNotNull($screen->form);
            self::assertNotContains($this->roomName->key, $screen->form->editableKeys(), 'The record form does not show the hidden required field.');
            $id = $records->create($admin, self::NEW_KEY, $this->rooms->key, []);
            self::assertGreaterThan($this->roomIds[1], $id, 'A record without the hidden required field is accepted.');
        } finally {
            $core->remove();
        }
    }

    public function testVersionOneFileGetsGeneratedForms(): void
    {
        $copy = $this->copyOfSource();
        self::rawEdit(
            $copy,
            'DROP TABLE _tiffin_collections',
            'DROP TABLE _tiffin_import_log',
            'DROP TABLE _tiffin_forms',
            'PRAGMA user_version = 1',
            "UPDATE _tiffin_meta SET value = '1' WHERE key = 'format_version'",
        );
        [$report, $db] = $this->import($this->zipFor($copy));
        self::assertSame(0, $report->formsDropped);
        self::assertSame(2, $db->value('SELECT COUNT(*) FROM "_tiffin_forms" WHERE "is_default" = 1 AND "generated" = 1'));
        self::assertSame('4', $db->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = \'format_version\''));
    }
}
