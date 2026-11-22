<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Roundtrip;

use PHPUnit\Framework\Attributes\CoversNothing;
use TiffinDB\Core\App\FormEditorScreen;
use TiffinDB\Core\App\ListRequest;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\ProjectFile;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Tests\Support\M4Fixture;
use TiffinDB\Tests\Support\M5Fixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/RoundTripCase.php';

/**
 * T-RT-9 to T-RT-11 (M5 spec section 11.6), through the core operations as
 * the screens call them:
 *
 * - T-RT-9: the M5 fixture form goes editor save, source view, download,
 *   upload of the downloaded bytes, editor again, and the stored bytes
 *   are the same at every step (read with plain SQL); a hand-edited source
 *   with comments and odd spacing is stored as its canonical text, which
 *   is then what the editor shows.
 * - T-RT-10: the edited form (a hidden required field, widths, a link, a
 *   heading, a section of its own) survives project archive export and
 *   import byte for byte, and the hidden field stays hidden.
 * - T-RT-11: a version 4 project folder written by the M4 code (M4Fixture)
 *   opens, its forms are edited and saved, and the file still opens with
 *   plain SQLite (integrity and foreign-key checks, the identity pragmas)
 *   and through ProjectFile::open without an upgrade.
 *
 * Synthetic data only.
 */
#[CoversNothing]
final class FormRoundTripTest extends RoundTripCase
{
    public function testEditorSourceDownloadUploadAndEditorGiveTheSameBytes(): void
    {
        $fx = M5Fixture::build($this->core, $this->ops);
        $p = $fx->m2->project;
        $items = $fx->m2->items;
        $forms = $this->ops->forms();
        $stored = $this->storedHtml($p, $items);
        self::assertSame($fx->html, $stored);
        self::assertSame((new FormSerializer())->serialize((new FormParser(FieldTypes::standard()))->parse($fx->source)), $stored, 'The fixture is stored as its canonical text.');

        // Editor: the page's draft is the stored text; saving it as the page posts it stores the same bytes.
        $editor = $forms->editor($this->admin, $p, $items);
        self::assertSame($stored, $editor->draft);
        $saved = $forms->editor($this->admin, $p, $items, self::pageInput($editor, 'save'));
        self::assertSame([], $saved->problems);
        self::assertNotNull($saved->saved);
        self::assertFalse($saved->saved->changed, 'Nothing new to store.');
        self::assertSame($stored, $this->storedHtml($p, $items), 'After the editor save.');

        // Source view: the text area holds the stored bytes; saving them unchanged is not a rewrite.
        $source = $forms->source($this->admin, $p, $items);
        self::assertSame($stored, $source->text);
        $sourceSaved = $forms->source($this->admin, $p, $items, ['text' => $source->text, 'base' => $source->base, 'do' => 'save']);
        self::assertSame([], $sourceSaved->problems);
        self::assertNotNull($sourceSaved->saved);
        self::assertFalse($sourceSaved->saved->changed);
        self::assertFalse($sourceSaved->saved->rewritten);
        self::assertSame($stored, $this->storedHtml($p, $items), 'After the source save.');

        // Download, then upload of that file.
        $file = $forms->download($this->admin, $p, $items);
        self::assertSame('Items form.html', $file->fileName);
        self::assertSame($stored, $file->text);
        $uploaded = $forms->upload($this->admin, $p, $items, $this->core->upload($file->text));
        self::assertFalse($uploaded->changed);
        self::assertFalse($uploaded->rewritten);
        self::assertFalse($uploaded->generated);
        self::assertSame($stored, $this->storedHtml($p, $items), 'After the upload.');

        // The editor again.
        self::assertSame($stored, $forms->editor($this->admin, $p, $items)->draft);

        // A hand-edited source: comments, odd spacing, line breaks inside tags, CR LF.
        $hand = str_replace(
            ["\n", '<fieldset>', '<legend>' . M5Fixture::SECTION . '</legend>', '</div>' . "\n" . '</fieldset>', '<h3>'],
            ["\r\n", "<!-- a section of its own -->\r\n<fieldset  >", "\r\n\t\t<legend>" . M5Fixture::SECTION . '</legend>', "</div>\r\n   \r\n</fieldset >", "\r\n<!-- the heading -->\r\n<h3\r\n>"],
            $fx->source,
        );
        $hand = "<!-- Items form, edited by hand -->\r\n" . $hand . "\r\n\r\n";
        self::assertNotSame($stored, $hand);
        $before = $forms->source($this->admin, $p, $items);
        $handSaved = $forms->source($this->admin, $p, $items, ['text' => $hand, 'base' => $before->base, 'do' => 'save']);
        self::assertSame([], $handSaved->problems);
        self::assertNotNull($handSaved->saved);
        self::assertTrue($handSaved->saved->rewritten, 'TiffinDB rewrote the text.');
        self::assertFalse($handSaved->saved->changed, 'The same document as before.');
        self::assertSame($stored, $this->storedHtml($p, $items), 'The canonical text is stored.');
        self::assertSame($stored, $handSaved->text);
        self::assertSame($stored, $forms->editor($this->admin, $p, $items)->draft, 'The editor shows the canonical text.');

        // Changed by hand and saved: the editor's output equals the stored canonical text.
        $changed = str_replace('before cleaning.', "  before\r\n   cleaning,   and after use. ", $hand);
        $before = $forms->source($this->admin, $p, $items);
        $changedSaved = $forms->source($this->admin, $p, $items, ['text' => $changed, 'base' => $before->base, 'do' => 'save']);
        self::assertSame([], $changedSaved->problems);
        self::assertNotNull($changedSaved->saved);
        self::assertTrue($changedSaved->saved->changed);
        $canonical = $this->storedHtml($p, $items);
        self::assertNotSame($stored, $canonical);
        self::assertSame((new FormSerializer())->serialize((new FormParser(FieldTypes::standard()))->parse($changed)), $canonical);
        $editor = $forms->editor($this->admin, $p, $items);
        self::assertSame($canonical, $editor->draft);
        $again = $forms->editor($this->admin, $p, $items, self::pageInput($editor, 'save'));
        self::assertNotNull($again->saved);
        self::assertFalse($again->saved->changed);
        self::assertSame($canonical, $this->storedHtml($p, $items));
    }

    public function testTheEditedFormSurvivesArchiveExportAndImport(): void
    {
        $fx = M5Fixture::build($this->core, $this->ops);
        $p = $fx->m2->project;
        $items = $fx->m2->items;
        $hidden = $fx->itemFields[M5Fixture::HIDDEN];
        $original = $this->storedHtml($p, $items);
        $originalRow = $this->formRow($p, $items);

        $zip = $this->archive($p);
        $this->core->clock->advance(3600);
        $result = $this->ops->archives()->import($this->admin, $this->core->upload($zip), 'home-inventory.tiffindb.zip', 'Home Inventory copy');
        $copy = $result->project->key;
        self::assertSame([0, 0], [$result->report->formsDropped, $result->report->formsExtended]);

        self::assertSame($original, $this->storedHtml($copy, $items), 'The same bytes.');
        $copyRow = $this->formRow($copy, $items);
        self::assertSame([0, 1, 1, ''], [$copyRow['generated'], $copyRow['is_default'], $copyRow['format'], $copyRow['css']]);
        self::assertSame($originalRow['key'], $copyRow['key']);
        self::assertSame($original, $this->ops->forms()->download($this->admin, $copy, $items)->text);

        // Widths, the link, the heading, and the section are in the copy's text; the hidden field is not.
        self::assertStringContainsString('data-tiffin-span="8"', $original);
        self::assertStringContainsString('<a href="' . M5Fixture::LINK . '">', $original);
        self::assertStringContainsString('<h3>' . M5Fixture::HEADING . '</h3>', $original);
        self::assertStringNotContainsString($hidden, $original);
        $editor = $this->ops->forms()->editor($this->admin, $copy, $items);
        self::assertSame($original, $editor->draft);
        self::assertNotContains($hidden, array_map(static fn ($row): ?string => $row->fieldKey, $editor->rows), 'The hidden field stays hidden.');
        self::assertContains($hidden, array_map(static fn ($field): string => $field->key, $editor->report->requiredNotOnForm));
        $record = $this->ops->records()->screen($this->admin, $copy, $items, (string) $fx->m2->itemIds['Stand mixer'], ListRequest::none());
        self::assertStringNotContainsString($hidden, serialize($record->form), 'The record screen does not show it.');
        self::assertStringContainsString($fx->itemFields['Name'], serialize($record->form));
    }

    public function testAVersionFourFolderOpensIsEditedAndStillPassesTheChecks(): void
    {
        $fx = M4Fixture::build($this->core, $this->ops);
        $m2 = $fx->m2;
        $p = $m2->project;
        $path = $this->projectFile($p);
        self::assertSame(4, self::pragma($path, 'user_version'), 'Written by the M4 code: version 4.');
        $before = self::dump($path);

        // Edit every table's form: hide a field and set a width on the first, save.
        $forms = $this->ops->forms();
        foreach ([$m2->items, $m2->rooms, $m2->warranties] as $table) {
            $editor = $forms->editor($this->admin, $p, $table);
            $first = null;
            foreach ($editor->rows as $row) {
                if ($row->kind === 'field') {
                    $first ??= $row;
                }
            }
            self::assertNotNull($first);
            $input = self::pageInput($editor, 'hide-' . $first->path);
            $hidden = $forms->editor($this->admin, $p, $table, $input);
            self::assertSame([], $hidden->problems);
            $widthInput = self::pageInput($hidden, 'save');
            foreach ($hidden->rows as $row) {
                if ($row->kind === 'field') {
                    $widthInput['width'][$row->path] = 'half';
                    break;
                }
            }
            $saved = $forms->editor($this->admin, $p, $table, $widthInput);
            self::assertSame([], $saved->problems);
            self::assertNotNull($saved->saved);
            self::assertTrue($saved->saved->changed);
            self::assertStringNotContainsString('data-tiffin-field="' . $first->fieldKey . '"', $this->storedHtml($p, $table));
            self::assertStringContainsString('data-tiffin-span="6"', $this->storedHtml($p, $table));
        }

        // Plain SQLite: the identity pragmas, the integrity and foreign-key checks.
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame([['ok']], $pdo->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_NUM));
        self::assertSame(4, (int) $pdo->query('PRAGMA user_version')->fetchColumn());
        self::assertSame(ProjectFile::APPLICATION_ID, (int) $pdo->query('PRAGMA application_id')->fetchColumn());
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM "_tiffin_forms" WHERE "is_default" = 1 AND "generated" = 0')->fetchColumn());
        $pdo = null;

        // Nothing but the forms and the last-change meta values changed.
        $after = self::dump($path);
        self::assertSame($before['#schema'], $after['#schema'], 'The schema is unchanged.');
        foreach ($before as $name => $rows) {
            if (!in_array($name, ['_tiffin_forms', '_tiffin_meta'], true)) {
                self::assertSame($rows, $after[$name], "{$name} is unchanged.");
            }
        }

        // ProjectFile checks: the file opens as this project, with no upgrade (no snapshot).
        $folder = $this->core->projectFolder($p);
        $snapshotsBefore = is_dir($folder . '/snapshots') ? scandir($folder . '/snapshots') : [];
        $db = ProjectFile::open($path, $p, new Snapshots($this->core->clock, new Keys(new SeededRandom('form-round-trip-snapshots'))));
        try {
            self::assertSame(4, $db->value('PRAGMA user_version'));
        } finally {
            $db->close();
        }
        self::assertSame($snapshotsBefore, is_dir($folder . '/snapshots') ? scandir($folder . '/snapshots') : []);

        // And it still opens through the operations, the record screen included.
        $record = $this->ops->records()->screen($this->admin, $p, $m2->items, (string) $m2->itemIds['Stand mixer'], ListRequest::none());
        self::assertNotNull($record->form);
    }

    /**
     * The input the editor page posts with $do: the draft, base, and every
     * row's label, legend, and width as the page shows them.
     *
     * @return array<string, mixed>
     */
    private static function pageInput(FormEditorScreen $screen, string $do): array
    {
        $input = ['draft' => $screen->draft, 'base' => $screen->base, 'do' => $do, 'label' => [], 'legend' => [], 'width' => []];
        foreach ($screen->rows as $row) {
            if ($row->kind === 'section') {
                $input['legend'][$row->path] = (string) $row->legend;
            } elseif ($row->kind === 'field') {
                $input['label'][$row->path] = (string) $row->label;
                $input['width'][$row->path] = $row->width;
            }
        }

        return $input;
    }

    /** The default form's stored text for a table, read with plain SQL. */
    private function storedHtml(string $project, string $table): string
    {
        return (string) $this->formRow($project, $table)['html'];
    }

    /** @return array<string, mixed> the default form row of a table, read with plain SQL */
    private function formRow(string $project, string $table): array
    {
        $pdo = new \PDO('sqlite:' . $this->projectFile($project), null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare('SELECT * FROM "_tiffin_forms" WHERE "table_key" = ? AND "is_default" = 1');
        $statement->execute([$table]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'One default form.');

        return $rows[0];
    }

    private static function pragma(string $path, string $name): int
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return (int) $pdo->query('PRAGMA ' . $name)->fetchColumn();
    }
}
