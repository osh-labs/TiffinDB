<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\FormEditorScreen;
use TiffinDB\Core\App\FormFile;
use TiffinDB\Core\App\FormOperations;
use TiffinDB\Core\App\FormSaved;
use TiffinDB\Core\App\FormSourceScreen;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Forms\EditorAction;
use TiffinDB\Core\Forms\EditorRow;
use TiffinDB\Core\Forms\FormEditor;
use TiffinDB\Core\Forms\FormFormat;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditEntry;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;
use TiffinDB\Tests\Support\FileFixture;

require_once __DIR__ . '/M4AppTestCase.php';
require_once dirname(__DIR__) . '/forms/corpus/Corpus.php';

/**
 * T-APP-161 to T-APP-172 (M5 spec sections 9 and 11.4): the form editor,
 * source view, upload, download, and reset operations on the synthetic
 * M4 scene. The generated Items form is: 1 Details (1-1 Name, 1-2 Room,
 * 1-3 Count, 1-4 Done, 1-5 Kind), 2 Files (2-1 Photos, 2-2 Files), 3 the
 * links section of "Stored in" (3-1).
 */
#[CoversClass(FormOperations::class)]
#[CoversClass(FormEditorScreen::class)]
#[CoversClass(FormSourceScreen::class)]
#[CoversClass(FormSaved::class)]
#[CoversClass(FormFile::class)]
#[CoversClass(Operations::class)]
final class FormOperationsTest extends M4AppTestCase
{
    private const MARKER = Corpus::MARKER;
    private const OTHER_FORM = 'q7tn4mz3k2xa';

    private FormParser $parser;
    private FormSerializer $serializer;
    private string $generated;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FormParser(FieldTypes::standard());
        $this->serializer = new FormSerializer();
        $this->generated = $this->storedHtml();
    }

    // ------------------------------------------------------------ T-APP-161

    public function testTheEditorStartsFromTheStoredFormInEachState(): void
    {
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame([FormOperations::STATE_STORED, null, true, false, [], [], null, null], [$screen->state, $screen->storedProblem, $screen->isStandard, $screen->draftRefused, $screen->problems, $screen->typed, $screen->focus, $screen->saved]);
        self::assertSame($this->generated, $screen->draft);
        self::assertSame(hash('sha256', $this->generated), $screen->base);
        self::assertSame(['1', '1-1', '1-2', '1-3', '1-4', '1-5', '2', '2-1', '2-2', '3', '3-1'], array_map(static fn (EditorRow $r): string => $r->path, $screen->rows));
        self::assertSame($this->items, $screen->table->key);
        self::assertSame($this->p, $screen->project->key);

        // A stored text that is not canonical: the draft is its canonical text; the source view gives it exactly.
        $hand = "<div data-tiffin-form=\"1\">\n<!-- a note -->\n   <p>Example   text</p>\n\n</div>";
        $this->setHtml($hand);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame(FormOperations::STATE_STORED, $screen->state);
        self::assertSame($this->canonical($hand), $screen->draft);
        self::assertNotSame($hand, $screen->draft);
        self::assertFalse($screen->isStandard);
        self::assertSame(hash('sha256', $hand), $screen->base);
        self::assertSame($hand, $this->forms()->source($this->admin, $this->p, $this->items)->text);

        // A refused stored text: the generated form, with the refusal; the source view still gives the text exactly.
        $refused = "<div data-tiffin-form=\"1\">\n<script>x</script>\n</div>";
        $this->setHtml($refused);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame(FormOperations::STATE_REFUSED, $screen->state);
        self::assertEquals(new Problem(null, 'problem.form.element', ['line' => 2, 'element' => 'script']), $screen->storedProblem);
        self::assertSame($this->generated, $screen->draft);
        self::assertTrue($screen->isStandard);
        self::assertSame(hash('sha256', $refused), $screen->base);
        $source = $this->forms()->source($this->admin, $this->p, $this->items);
        self::assertSame([$refused, FormOperations::STATE_REFUSED, null, []], [$source->text, $source->state, $source->report, $source->problems]);
        self::assertEquals(new Problem('source', 'problem.form.element', ['line' => 2, 'element' => 'script']), $source->storedProblem);

        // Another format version.
        $this->setHtml($hand, 2);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame(FormOperations::STATE_REFUSED, $screen->state);
        self::assertEquals(new Problem(null, 'problem.form.format_version'), $screen->storedProblem);
        self::assertSame($this->generated, $screen->draft);

        // No row.
        $this->deleteDefault();
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame([FormOperations::STATE_NONE, null, $this->generated, hash('sha256', '')], [$screen->state, $screen->storedProblem, $screen->draft, $screen->base]);
        $source = $this->forms()->source($this->admin, $this->p, $this->items);
        self::assertSame([$this->generated, FormOperations::STATE_NONE, hash('sha256', '')], [$source->text, $source->state, $source->base]);
    }

    // ------------------------------------------------------------ T-APP-162

    public function testEachActionIsPerformedWithItsFocus(): void
    {
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        $editor = new FormEditor();
        $document = $this->parser->parse($start->draft);
        $fields = $this->fieldsOfItems();
        $added = $this->hide($start, '1-3');
        foreach (['up-1-2', 'down-1-1', 'down-1-5', 'up-2', 'up-2-1', 'hide-1-5', 'hide-3-1', 'ungroup-2', 'add-section', 'apply', 'save'] as $do) {
            $screen = $this->edit($start, $do);
            $action = EditorAction::parse($do);
            self::assertNotNull($action);
            $after = $editor->act($document, $action, $fields);
            self::assertSame([], $screen->problems, $do);
            self::assertSame($this->serializer->serialize($after), $screen->draft, $do);
            self::assertSame($editor->focusAfter($document, $action, $after), $screen->focus, $do);
            self::assertSame($start->base, $screen->base, $do);
        }
        self::assertSame(['path' => '1-1', 'control' => 'up'], $this->edit($start, 'up-1-2')->focus);
        self::assertSame(['path' => '1', 'control' => 'down'], $this->edit($start, 'up-1-1')->focus);
        self::assertSame(['path' => '4', 'control' => 'legend'], $this->edit($start, 'add-section')->focus);
        self::assertSame(['path' => '1-4', 'control' => 'first'], $this->edit($start, 'hide-1-4')->focus);
        self::assertNull($this->edit($start, 'apply')->focus);

        // Adding a field back: the slot is appended to the first section.
        $screen = $this->edit($added, 'add-' . $this->f['count']);
        self::assertSame([], $screen->problems);
        self::assertSame(['path' => '1-5', 'control' => 'up'], $screen->focus);
        self::assertSame($this->f['count'], $screen->rows[5]->fieldKey);

        // An unknown or non-string `do` is apply.
        foreach (['', 'delete-1', 'up-0', 'up-1-1-1', 'add-NOTAKEY', 'save ', 'hide-99999'] as $do) {
            $screen = $this->edit($start, $do);
            self::assertSame([[], $start->draft, null], [$screen->problems, $screen->draft, $screen->focus], $do);
        }
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $start->draft, 'base' => $start->base, 'do' => ['save']]);
        self::assertSame([[], null, $start->draft], [$screen->problems, $screen->saved, $screen->draft]);
        self::assertSame($this->generated, $this->storedHtml());
    }

    public function testInputProblemsStopTheActionAndKeepTheTypedText(): void
    {
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        $long = str_repeat('l', FormFormat::MAX_LABEL + 1);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, [
            'draft' => $start->draft,
            'base' => $start->base,
            'do' => 'hide-1-5',
            'label' => ['1-1' => $long, '1-2' => 'Example room'],
        ]);
        self::assertEquals([new Problem('label-1-1', 'problem.form.label', ['max' => FormFormat::MAX_LABEL])], $screen->problems);
        self::assertSame(['label-1-1' => $long], $screen->typed);
        self::assertNull($screen->focus);
        // The action was not performed; the accepted label is kept in the working copy.
        self::assertSame($this->f['kind'], $screen->rows[5]->fieldKey);
        self::assertSame('Example room', $screen->rows[2]->label);

        // A save with a problem saves nothing.
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $start->draft, 'base' => $start->base, 'do' => 'save', 'legend' => ['1' => "Two\nlines"]]);
        self::assertEquals([new Problem('legend-1', 'problem.form.legend', ['max' => FormFormat::MAX_LEGEND])], $screen->problems);
        self::assertNull($screen->saved);
        self::assertSame($this->generated, $this->storedHtml());

        // An action that does not fit: problem.form.action, with the inputs applied.
        foreach (['up-1', 'hide-1', 'remove-1-1', 'ungroup-1-1', 'add-' . $this->f['kind'], 'add-' . $this->f['roomName'], 'down-3'] as $do) {
            $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $start->draft, 'base' => $start->base, 'do' => $do, 'label' => ['1-2' => 'Example room']]);
            self::assertEquals([new Problem(null, 'problem.form.action')], $screen->problems, $do);
            self::assertNull($screen->focus);
            self::assertSame('Example room', $screen->rows[2]->label, $do);
        }

        // Arrays that are not arrays are ignored.
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $start->draft, 'do' => 'apply', 'label' => 'x', 'legend' => 3, 'width' => null]);
        self::assertSame([[], $start->draft], [$screen->problems, $screen->draft]);
    }

    public function testAWorkingCopyThatGrowsOverTheLimitIsNotKept(): void
    {
        $draft = $this->nearLimit(30);
        self::assertSame(FormFormat::MAX_BYTES - 30, strlen($draft));
        $base = hash('sha256', $this->generated);
        $tooLarge = [new Problem(null, 'problem.form.too_large', ['max' => FormFormat::MAX_BYTES])];

        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $draft, 'base' => $base, 'do' => 'apply']);
        self::assertSame([[], $draft], [$screen->problems, $screen->draft]);

        foreach ([['do' => 'add-section'], ['do' => 'save', 'label' => ['1' => str_repeat('w', FormFormat::MAX_LABEL)]], ['do' => 'add-' . $this->f['count']]] as $input) {
            $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $draft, 'base' => $base, ...$input]);
            self::assertEquals($tooLarge, $screen->problems);
            self::assertSame([$draft, null, null, []], [$screen->draft, $screen->saved, $screen->focus, $screen->typed]);
            self::assertFalse($screen->draftRefused);
        }
        self::assertSame($this->generated, $this->storedHtml());
        self::assertNotContains(AuditAction::FORM_SAVED, $this->auditActions());

        // At the limit exactly, a save goes through.
        $draft = $this->nearLimit(0);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $draft, 'base' => $base, 'do' => 'save']);
        self::assertSame([], $screen->problems);
        self::assertNotNull($screen->saved);
        self::assertSame(FormFormat::MAX_BYTES, strlen($this->storedHtml()));
    }

    // ------------------------------------------------------------ T-APP-163

    public function testATamperedDraftShowsTheStoredFormAndWritesNothing(): void
    {
        $this->setHtml($this->canonical('<div data-tiffin-form="1"><p>Example stored</p></div>'));
        $stored = $this->storedHtml();
        $before = $this->state();
        $drafts = [];
        foreach (Corpus::all() as $id => $case) {
            if ($case['verdict'] === 'refuse') {
                $drafts['corpus ' . $id] = $case['text'];
            }
        }
        self::assertGreaterThan(100, count($drafts));
        $drafts['over the limit'] = '<div data-tiffin-form="1"><p>' . str_repeat('a', FormFormat::MAX_BYTES) . '</p></div>';
        $drafts['truncated'] = substr($stored, 0, 40);
        $drafts['empty'] = '';
        $drafts['an array'] = [$stored];
        $drafts['a number'] = 7;
        $drafts['null'] = null;

        foreach ($drafts as $name => $draft) {
            $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $draft, 'base' => hash('sha256', $stored), 'do' => 'save', 'label' => ['1' => 'Example']]);
            self::assertEquals([new Problem(null, 'problem.form.draft')], $screen->problems, (string) $name);
            self::assertTrue($screen->draftRefused, (string) $name);
            self::assertSame([$stored, hash('sha256', $stored), null, null, FormOperations::STATE_STORED], [$screen->draft, $screen->base, $screen->saved, $screen->focus, $screen->state], (string) $name);
        }
        // No draft at all.
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['do' => 'save']);
        self::assertTrue($screen->draftRefused);
        self::assertSame($before, $this->state());
    }

    // ------------------------------------------------------------ T-APP-164

    public function testAnEditorSaveWritesOnceAndIsAudited(): void
    {
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        $hidden = $this->hide($start, '1-5');
        $this->fx->clock->advance(60);
        $screen = $this->edit($hidden, 'save');
        self::assertEquals(new FormSaved($this->items, false, true, false), $screen->saved);
        self::assertSame([], $screen->problems);
        $row = $this->defaultRow();
        self::assertSame([$hidden->draft, 0], [$row['html'], $row['generated']]);
        self::assertSame(hash('sha256', $row['html']), $screen->base);
        self::assertSame($this->now(), $this->projectMeta('updated_at'));
        self::assertSame($this->admin->userKey, $this->projectMeta('updated_by'));
        $saved = $this->formAudits();
        self::assertCount(1, $saved);
        self::assertSame(['form', $row['key'], ['table' => $this->items, 'source' => 'editor', 'generated' => 0]], [$saved[0]->targetKind, $saved[0]->targetKey, $saved[0]->detail]);

        // The edit undone: the generated text again, marked generated.
        $restored = $this->edit($screen, 'add-' . $this->f['kind']);
        $screen = $this->edit($restored, 'save');
        self::assertEquals(new FormSaved($this->items, true, true, false), $screen->saved);
        self::assertTrue($screen->isStandard);
        self::assertSame([$this->generated, 1], [$this->defaultRow()['html'], $this->defaultRow()['generated']]);
        self::assertSame(['table' => $this->items, 'source' => 'editor', 'generated' => 1], $this->formAudits()[1]->detail);

        // Saving again with nothing changed: saved, unchanged, no write, no audit.
        $this->fx->clock->advance(60);
        $before = $this->projectMeta('updated_at');
        $screen = $this->edit($screen, 'save');
        self::assertEquals(new FormSaved($this->items, true, false, false), $screen->saved);
        self::assertSame($before, $this->projectMeta('updated_at'));
        self::assertCount(2, $this->formAudits());
    }

    // ------------------------------------------------------------ T-APP-165

    public function testASaveOverAnotherSaveIsRefusedOnce(): void
    {
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        $hidden = $this->hide($start, '1-5');
        $theirs = $this->canonical('<div data-tiffin-form="1"><p>Example other version</p></div>');
        self::assertNotNull($this->forms()->source($this->admin, $this->p, $this->items, ['text' => $theirs, 'base' => $start->base, 'do' => 'save'])->saved);

        $screen = $this->edit($hidden, 'save');
        self::assertEquals([new Problem(null, 'problem.form.changed')], $screen->problems);
        self::assertNull($screen->saved);
        self::assertSame(hash('sha256', $theirs), $screen->base);
        self::assertSame($hidden->draft, $screen->draft);
        self::assertSame($theirs, $this->storedHtml());
        self::assertCount(1, $this->formAudits());

        $screen = $this->edit($screen, 'save');
        self::assertSame([], $screen->problems);
        self::assertNotNull($screen->saved);
        self::assertSame($hidden->draft, $this->storedHtml());

        // The source view: the same rule.
        $source = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $theirs, 'base' => $start->base, 'do' => 'save']);
        self::assertEquals([new Problem(null, 'problem.form.changed')], $source->problems);
        self::assertSame([$theirs, hash('sha256', $hidden->draft), null], [$source->text, $source->base, $source->saved]);
        self::assertNotNull($source->report);
        self::assertSame($hidden->draft, $this->storedHtml());
        $source = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $theirs, 'base' => $source->base, 'do' => 'save']);
        self::assertNotNull($source->saved);
        self::assertSame($theirs, $this->storedHtml());
    }

    // ------------------------------------------------------------ T-APP-166

    public function testTheSourceViewChecksAndSaves(): void
    {
        $base = hash('sha256', $this->generated);
        $before = $this->state();

        $refused = "<div data-tiffin-form=\"1\">\n<p>Example</p>\n<script>" . self::MARKER . "</script>\n</div>";
        foreach (['check', 'save', 'other', ''] as $do) {
            $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $refused, 'base' => $base, 'do' => $do]);
            self::assertEquals([new Problem('source', 'problem.form.element', ['line' => 3, 'element' => 'script'])], $screen->problems);
            self::assertSame([$refused, $base, null, null, FormOperations::STATE_STORED], [$screen->text, $screen->base, $screen->report, $screen->saved, $screen->state]);
        }
        $z9 = Corpus::all()['Z9']['text'];
        self::assertIsString($z9);
        foreach (['check', 'save'] as $do) {
            $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $z9, 'base' => $base, 'do' => $do]);
            self::assertEquals([new Problem('source', 'problem.form.too_large', ['max' => FormFormat::MAX_BYTES])], $screen->problems, $do);
            self::assertSame([$z9, null, null], [$screen->text, $screen->report, $screen->saved]);
        }
        foreach (['text' => 7, 'base' => [], 'do' => ['save']] as $name => $value) {
            $screen = $this->forms()->source($this->admin, $this->p, $this->items, [...['text' => $this->generated, 'base' => $base, 'do' => 'save'], $name => $value]);
            self::assertNull($screen->saved, $name);
        }
        self::assertSame($before, $this->state());

        // Check: the report; nothing stored; the text as typed.
        $hand = "<div data-tiffin-form=\"1\">\n<!-- a note -->\n<p>Example   text</p>\r\n</div>";
        $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $hand, 'base' => $base, 'do' => 'check']);
        self::assertSame([[], $hand, $base, null], [$screen->problems, $screen->text, $screen->base, $screen->saved]);
        self::assertNotNull($screen->report);
        self::assertCount(count($this->fieldsOfItems()), $screen->report->notOnForm);
        self::assertSame($before, $this->state());

        // Save: the canonical text is stored and shown; rewritten.
        $this->fx->clock->advance(60);
        $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $hand, 'base' => $base, 'do' => 'save']);
        self::assertEquals(new FormSaved($this->items, false, true, true), $screen->saved);
        self::assertSame($this->canonical($hand), $this->storedHtml());
        self::assertSame([$this->storedHtml(), hash('sha256', $this->storedHtml()), FormOperations::STATE_STORED], [$screen->text, $screen->base, $screen->state]);
        self::assertSame($this->now(), $this->projectMeta('updated_at'));
        self::assertSame(['table' => $this->items, 'source' => 'source', 'generated' => 0], $this->formAudits()[0]->detail);

        // Canonical text sent with CR LF line breaks: not rewritten, and nothing changed.
        $crlf = str_replace("\n", "\r\n", $this->storedHtml());
        $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $crlf, 'base' => $screen->base, 'do' => 'save']);
        self::assertEquals(new FormSaved($this->items, false, false, false), $screen->saved);
        self::assertCount(1, $this->formAudits());

        // A refused stored text can be replaced from the source view.
        $this->setHtml($refused);
        $screen = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $this->generated, 'base' => hash('sha256', $refused), 'do' => 'save']);
        self::assertEquals(new FormSaved($this->items, true, true, false), $screen->saved);
        self::assertSame([FormOperations::STATE_STORED, null], [$screen->state, $screen->storedProblem]);
    }

    public function testTheReportNamesThePicturesThatCannotBeShown(): void
    {
        $shown = $this->attach($this->f['photos'], 'Example lamp', self::png('shown'))->id;
        $trashed = $this->attach($this->f['photos'], 'Example kettle', self::png('trashed'))->id;
        $pdf = $this->attach($this->f['files'], 'Example lamp', FileFixture::pdf(), 'example.pdf')->id;
        $this->ops->records()->trash($this->admin, $this->p, $this->items, (string) $this->ids['Example kettle']);
        $missing = 999;
        $images = '';
        foreach ([$shown, $trashed, $pdf, $missing] as $id) {
            $images .= '<img src="attachment:' . $id . '" alt="Example picture"/>';
        }
        $text = '<div data-tiffin-form="1">' . $images . '<p><a href="https://example.com/"></a></p></div>';
        $source = $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $text, 'do' => 'check']);
        self::assertNotNull($source->report);
        self::assertSame([[$shown, $trashed, $pdf, $missing], [$trashed, $pdf, $missing], 1], [$source->report->images, $source->report->imagesNotShown, $source->report->emptyLinks]);

        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $this->canonical($text), 'do' => 'apply']);
        self::assertSame([$trashed, $pdf, $missing], $screen->report->imagesNotShown);
    }

    // ------------------------------------------------------------ T-APP-167

    public function testAnUploadIsCheckedThenStored(): void
    {
        $before = $this->state();
        $tooLarge = [['file', 'problem.form.too_large', ['max' => FormFormat::MAX_BYTES]]];
        $cases = [
            'empty' => [$this->fx->upload(''), [['file', 'problem.form.empty_file', []]]],
            'over the cap' => [$this->fx->upload(str_repeat(' ', FormFormat::MAX_BYTES + 1)), $tooLarge],
            'refused' => [$this->fx->upload("<div data-tiffin-form=\"1\">\n<script>" . self::MARKER . "</script></div>"), [['file', 'problem.form.element', ['line' => 2, 'element' => 'script']]]],
            'too deep' => [$this->fx->upload(self::deep(FormFormat::MAX_DEPTH + 2)), null],
            'Z9' => [$this->fx->upload((string) Corpus::all()['Z9']['text']), $tooLarge],
            'missing' => [$this->fx->temp->path('no-such-upload'), [['file', 'problem.upload.missing', []]]],
            'no path' => ['', [['file', 'problem.upload.missing', []]]],
            'a folder' => [$this->fx->temp->path(''), [['file', 'problem.upload.missing', []]]],
        ];
        foreach ($cases as $name => [$path, $expected]) {
            $problems = self::problems(fn () => $this->forms()->upload($this->admin, $this->p, $this->items, $path));
            if ($expected === null) {
                self::assertCount(1, $problems, $name);
                self::assertSame('file', $problems[0][0], $name);
                self::assertStringStartsWith('problem.form.', $problems[0][1], $name);
            } else {
                self::assertSame($expected, $problems, $name);
            }
        }
        self::assertSame($before, $this->state());

        // Accepted: stored at once, without a save token, as canonical text.
        $hand = "\u{FEFF}<div data-tiffin-form=\"1\">\r\n<p>Example upload</p>\r\n</div>";
        $saved = $this->forms()->upload($this->admin, $this->p, $this->items, $this->fx->upload($hand));
        self::assertEquals(new FormSaved($this->items, false, true, true), $saved);
        self::assertSame($this->canonical($hand), $this->storedHtml());
        self::assertSame(['table' => $this->items, 'source' => 'upload', 'generated' => 0], $this->formAudits()[0]->detail);

        // The canonical text uploaded again: nothing changes.
        $saved = $this->forms()->upload($this->admin, $this->p, $this->items, $this->fx->upload($this->storedHtml()));
        self::assertEquals(new FormSaved($this->items, false, false, false), $saved);
        self::assertCount(1, $this->formAudits());
    }

    // ------------------------------------------------------------ T-APP-168

    public function testDownloadGivesTheStoredTextExactly(): void
    {
        $file = $this->forms()->download($this->admin, $this->p, $this->items);
        self::assertSame(['Items form.html', $this->generated], [$file->fileName, $file->text]);
        foreach (["<div data-tiffin-form=\"1\">\n<!-- a note -->\n<p>x</p></div>", "<div data-tiffin-form=\"1\"><script>x</script></div>", 'not a form at all'] as $text) {
            $this->setHtml($text);
            $before = $this->state();
            self::assertSame($text, $this->forms()->download($this->admin, $this->p, $this->items)->text);
            self::assertSame($before, $this->state());
        }
        $this->deleteDefault();
        self::assertSame($this->generated, $this->forms()->download($this->admin, $this->p, $this->items)->text);
        self::assertSame(0, (int) $this->fx->projectDb($this->p)->value('SELECT COUNT(*) FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]));
        self::assertSame('Rooms form.html', $this->forms()->download($this->admin, $this->p, $this->rooms)->fileName);
    }

    // ------------------------------------------------------------ T-APP-169

    public function testResetMakesTheFormStandardAndIsAuditedWhenItChanges(): void
    {
        self::assertTrue($this->forms()->resetScreen($this->admin, $this->p, $this->items)->isStandard);
        self::assertFalse($this->forms()->reset($this->admin, $this->p, $this->items));
        self::assertNotContains(AuditAction::FORM_RESET, $this->auditActions());

        $this->forms()->source($this->admin, $this->p, $this->items, ['text' => '<div data-tiffin-form="1"><p>Example</p></div>', 'base' => hash('sha256', $this->generated), 'do' => 'save']);
        $screen = $this->forms()->resetScreen($this->admin, $this->p, $this->items);
        self::assertSame([false, $this->items, $this->p], [$screen->isStandard, $screen->table->key, $screen->project->key]);
        $this->fx->clock->advance(60);
        self::assertTrue($this->forms()->reset($this->admin, $this->p, $this->items));
        self::assertSame([$this->generated, 1], [$this->defaultRow()['html'], $this->defaultRow()['generated']]);
        self::assertSame($this->now(), $this->projectMeta('updated_at'));
        $reset = $this->lastAudit(AuditAction::FORM_RESET);
        self::assertSame(['form', $this->defaultRow()['key'], ['table' => $this->items]], [$reset->targetKind, $reset->targetKey, $reset->detail]);
        self::assertTrue($this->forms()->resetScreen($this->admin, $this->p, $this->items)->isStandard);
        self::assertFalse($this->forms()->reset($this->admin, $this->p, $this->items));
        self::assertCount(1, array_filter($this->auditActions(), static fn (string $a): bool => $a === AuditAction::FORM_RESET));

        // The generated text marked as edited, and a row with no text of its own: not standard; reset changes them.
        $this->fx->projectDb($this->p)->run('UPDATE "_tiffin_forms" SET "generated" = 0 WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]);
        self::assertFalse($this->forms()->resetScreen($this->admin, $this->p, $this->items)->isStandard);
        self::assertTrue($this->forms()->reset($this->admin, $this->p, $this->items));
        $this->deleteDefault();
        self::assertFalse($this->forms()->resetScreen($this->admin, $this->p, $this->items)->isStandard);
        self::assertTrue($this->forms()->reset($this->admin, $this->p, $this->items));
        self::assertSame($this->generated, $this->storedHtml());

        $upload = $this->forms()->uploadScreen($this->admin, $this->p, $this->items);
        self::assertSame([FormFormat::MAX_BYTES, $this->items], [$upload->maxBytes, $upload->table->key]);
    }

    // ------------------------------------------------------------ T-APP-170

    public function testNoLogLineAndNoAuditDetailHoldsFormText(): void
    {
        $this->captureLog();
        $m = self::MARKER;
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        $screen = $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $start->draft, 'base' => $start->base, 'do' => 'save', 'label' => ['1-1' => 'Example ' . $m], 'legend' => ['1' => 'Legend ' . $m]]);
        self::assertNotNull($screen->saved);
        $text = '<div data-tiffin-form="1"><h2>' . $m . '</h2><p>' . $m . ' <a href="https://example.com/' . $m . '">' . $m . '</a></p><img src="attachment:9" alt="' . $m . '"/></div>';
        self::assertNotNull($this->forms()->source($this->admin, $this->p, $this->items, ['text' => $text, 'base' => $screen->base, 'do' => 'save'])->saved);
        $this->forms()->upload($this->admin, $this->p, $this->items, $this->fx->upload(str_replace('<h2>', '<h3>', str_replace('</h2>', '</h3>', $text))));
        $refusals = [];
        foreach (Corpus::all() as $case) {
            if ($case['verdict'] === 'refuse' && str_contains((string) $case['text'], $m) && strlen((string) $case['text']) < 4096) {
                $refusals[] = (string) $case['text'];
            }
        }
        self::assertGreaterThan(20, count($refusals));
        foreach ($refusals as $refused) {
            $problems = [
                ...$this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $refused, 'do' => 'save'])->problems,
                ...$this->forms()->source($this->admin, $this->p, $this->items, ['text' => $refused, 'do' => 'save'])->problems,
                ...array_map(static fn (array $p): Problem => new Problem($p[0], $p[1], $p[2]), self::problems(fn () => $this->forms()->upload($this->admin, $this->p, $this->items, $this->fx->upload($refused)))),
            ];
            foreach ($problems as $problem) {
                self::assertStringNotContainsString($m, json_encode($problem->params, JSON_THROW_ON_ERROR) . $problem->key);
            }
        }
        $this->setHtml('<div data-tiffin-form="1"><script>' . $m . '</script></div>');
        $this->forms()->editor($this->admin, $this->p, $this->items);
        $this->forms()->source($this->admin, $this->p, $this->items);
        $this->forms()->download($this->admin, $this->p, $this->items);
        $this->forms()->reset($this->admin, $this->p, $this->items);

        self::assertStringNotContainsString($m, $this->errorLog());
        self::assertGreaterThanOrEqual(3, count($this->formAudits()));
        foreach ($this->auditEntries() as $entry) {
            self::assertStringNotContainsString($m, json_encode($entry->detail, JSON_THROW_ON_ERROR) . $entry->targetKey);
            self::assertStringNotContainsString('Example', json_encode($entry->detail, JSON_THROW_ON_ERROR), $entry->action);
        }
    }

    // ------------------------------------------------------------ T-APP-171

    public function testATableWithNoRowAndTheOtherFormsOfTheTable(): void
    {
        $db = $this->fx->projectDb($this->p);
        $db->run(
            'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at") VALUES (?, ?, ?, 1, ?, ?, 0, 0, ?, ?)',
            [self::OTHER_FORM, $this->items, 'Example other form', '<div data-tiffin-form="1"><p>Other</p></div>', 'p{}', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'],
        );
        $other = fn (): array => (array) $db->one('SELECT * FROM "_tiffin_forms" WHERE "key" = ?', [self::OTHER_FORM]);
        $kept = $other();
        $this->deleteDefault();

        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertSame(FormOperations::STATE_NONE, $start->state);
        self::assertSame($this->generated, $start->draft);
        $hidden = $this->hide($start, '1-5');
        $screen = $this->edit($hidden, 'save');
        self::assertEquals(new FormSaved($this->items, false, true, false), $screen->saved);
        $row = $this->defaultRow();
        self::assertSame([FormOperations::STATE_STORED, $hidden->draft, 0, '', 'Standard form', 1], [$screen->state, $row['html'], $row['generated'], $row['css'], $row['name'], $row['is_default']]);
        self::assertSame($row['key'], $this->formAudits()[0]->targetKey);
        self::assertSame($kept, $other());

        $this->forms()->source($this->admin, $this->p, $this->items, ['text' => $this->generated, 'base' => $screen->base, 'do' => 'save']);
        $this->forms()->upload($this->admin, $this->p, $this->items, $this->fx->upload($hidden->draft));
        $this->forms()->download($this->admin, $this->p, $this->items);
        $this->forms()->resetScreen($this->admin, $this->p, $this->items);
        $this->forms()->reset($this->admin, $this->p, $this->items);
        self::assertSame($kept, $other());
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM "_tiffin_forms" WHERE "table_key" = ?', [$this->items]));
    }

    // ------------------------------------------------------------ T-APP-172

    public function testASchemaChangeAfterASaveKeepsHiddenFieldsHidden(): void
    {
        $start = $this->forms()->editor($this->admin, $this->p, $this->items);
        self::assertNotNull($this->edit($this->hide($start, '1-5'), 'save')->saved);
        $colour = $this->ops->schema()->addField($this->admin, $this->p, $this->items, 'Colour', 'text', [], false, '')->key;
        $this->ops->schema()->updateField($this->admin, $this->p, $this->items, $this->f['count'], 'Example count', [], false, '', 0);

        $keys = array_values(array_filter(array_map(static fn (EditorRow $r): ?string => $r->fieldKey, $this->forms()->editor($this->admin, $this->p, $this->items)->rows)));
        self::assertContains($colour, $keys);
        self::assertNotContains($this->f['kind'], $keys);
        self::assertSame($colour, $keys[4]);
    }

    // ------------------------------------------------------------ helpers

    private function forms(): FormOperations
    {
        return $this->ops->forms();
    }

    /** The editor after $do on the screen's draft, with its base. */
    private function edit(FormEditorScreen $screen, string $do): FormEditorScreen
    {
        return $this->forms()->editor($this->admin, $this->p, $this->items, ['draft' => $screen->draft, 'base' => $screen->base, 'do' => $do]);
    }

    private function hide(FormEditorScreen $screen, string $path): FormEditorScreen
    {
        $hidden = $this->edit($screen, 'hide-' . $path);
        self::assertSame([], $hidden->problems);

        return $hidden;
    }

    private function canonical(string $text): string
    {
        return $this->serializer->serialize($this->parser->parse($text));
    }

    /** A canonical form of the Kind slot and one paragraph, exactly $short bytes under the limit. */
    private function nearLimit(int $short): string
    {
        $make = fn (int $n): string => $this->canonical('<div data-tiffin-form="1"><div data-tiffin-field="' . $this->f['kind'] . '"></div><p>' . str_repeat('a', $n) . '</p></div>');
        $fixed = strlen($make(1)) - 1;

        return $make(FormFormat::MAX_BYTES - $short - $fixed);
    }

    /** Nested sections and paragraphs deeper than the format allows. */
    private static function deep(int $levels): string
    {
        return '<div data-tiffin-form="1">' . str_repeat('<fieldset>', $levels) . str_repeat('</fieldset>', $levels) . '</div>';
    }

    /** @return list<\TiffinDB\Core\Schema\FieldDef> */
    private function fieldsOfItems(): array
    {
        return array_map(static fn ($design) => $design->field, $this->ops->schema()->design($this->admin, $this->p, $this->items)->fields);
    }

    private function setHtml(string $html, int $format = 1): void
    {
        $this->fx->projectDb($this->p)->run(
            'UPDATE "_tiffin_forms" SET "html" = ?, "format" = ?, "generated" = 0 WHERE "is_default" = 1 AND "table_key" = ?',
            [$html, $format, $this->items],
        );
    }

    private function deleteDefault(): void
    {
        $this->fx->projectDb($this->p)->run('DELETE FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]);
    }

    /** @return array<string, mixed> */
    private function defaultRow(): array
    {
        $row = $this->fx->projectDb($this->p)->one('SELECT * FROM "_tiffin_forms" WHERE "is_default" = 1 AND "table_key" = ?', [$this->items]);
        self::assertNotNull($row);

        return $row;
    }

    private function storedHtml(): string
    {
        return (string) $this->defaultRow()['html'];
    }

    private function projectMeta(string $key): string
    {
        return (string) $this->fx->projectDb($this->p)->value('SELECT "value" FROM "_tiffin_meta" WHERE "key" = ?', [$key]);
    }

    private function now(): string
    {
        return $this->fx->clock->now()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return list<AuditEntry> form.saved entries, oldest first */
    private function formAudits(): array
    {
        return array_values(array_filter($this->auditEntries(), static fn (AuditEntry $e): bool => $e->action === AuditAction::FORM_SAVED));
    }

    /** Every form row, the project meta, and the audit actions. @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>} */
    private function state(): array
    {
        $db = $this->fx->projectDb($this->p);

        return [$db->all('SELECT * FROM "_tiffin_forms" ORDER BY "key"'), $db->all('SELECT * FROM "_tiffin_meta" ORDER BY "key"'), $this->auditActions()];
    }
}
