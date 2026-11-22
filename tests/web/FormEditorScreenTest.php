<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\FormController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';
require_once __DIR__ . '/../core/forms/corpus/Corpus.php';

/**
 * T-WEB-75 (editor and reset routes) and T-WEB-78 (M5-WP5): the simple
 * form editor and the "back to the standard form" confirmation of
 * docs/design/m5/editor.md and reset.md, through the kernel with the real
 * templates and strings and real core operations over a CoreFixture
 * instance. Every action is a plain form post; the returned page puts
 * `autofocus` on the control the core chose; a tampered draft is 422 with
 * a problem, never 500; CSRF, a cross-origin post, a Member, and an
 * ungranted person are refused. Synthetic data only.
 */
#[CoversClass(FormController::class)]
final class FormEditorScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;
    private const HOSTILE = '<b>"x" & \'y\'</b>';

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    /** @var array<string, string> field name => key */
    private array $f = [];
    /** The standard form's canonical text, as the editor first shows it. */
    private string $standard;

    protected function setUp(): void
    {
        $this->core = new CoreFixture();
        $this->ops = $this->core->operations();
        $this->services = new FakeServices();
        $this->services->operations = $this->ops;
        $this->services->sessions[self::ADMIN] = $this->signedIn('admin');
        $this->services->sessions[self::MEMBER] = $this->signedIn('member');
        $this->kernel = new Kernel(Fixtures::config(false), Fixtures::appRoot(), Fixtures::applicationRoutes(), $this->services);

        $schema = $this->ops->schema();
        $admin = $this->admin();
        $this->project = $this->ops->projects()->create($admin, 'Home Inventory', 'Items')->key;
        $this->items = $schema->tables($admin, $this->project)[0]->table->key;
        $this->f['Name'] = $schema->design($admin, $this->project, $this->items)->fields[0]->field->key;
        foreach (['Brand' => false, 'Serial number' => true, 'Price' => false] as $name => $required) {
            $this->f[$name] = $schema->addField($admin, $this->project, $this->items, $name, 'text', [], $required, '')->key;
        }
        $rooms = $schema->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
        $this->f['Room'] = $schema->addRelationField($admin, $this->project, $this->items, 'Room', $rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->standard = $this->ops->forms()->editor($admin, $this->project, $this->items)->draft;
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ------------------------------------------------------------------ the page (editor.md section 2)

    public function testTheStandardForm(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/form'));
        $x = $this->xpath($html);

        self::assertSame('Form for Items - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Form'], $this->crumbs($x));
        self::assertSame($this->t('/fields'), $this->attr($x, '//nav[@class="breadcrumb"]/a[3]', 'href'));
        self::assertSame('page page-form', $this->attr($x, '//main', 'class'));
        self::assertSame('Form for Items', HtmlAssertions::text($html, '//main//h1'));
        self::assertStringStartsWith('Choose which fields the record screen shows', HtmlAssertions::text($html, '//main//p[@class="lead"]'));
        self::assertSame('page', $this->attr($x, '//nav[@class="form-tabs"]//a[text()="Arrange"]', 'aria-current'));
        self::assertSame('This is the standard form. It follows the table\'s fields by itself until you save a change.', HtmlAssertions::text($html, '//p[@class="notice notice-muted"]'));
        self::assertSame(0, $x->query('//script')->length);
        self::assertSame(0, $x->query('//*[@autofocus]')->length, 'No autofocus before a change.');

        $form = '//form[@id="form-editor"]';
        self::assertSame(['post', $this->t('/form')], [$this->attr($x, $form, 'method'), $this->attr($x, $form, 'action')]);
        self::assertSame(1, $x->query('//main//form')->length, 'One form in the page.');
        self::assertNotSame('', (string) $this->attr($x, $form . '/input[@name="base"]', 'value'));
        self::assertSame($this->ops->forms()->editor($this->admin(), $this->project, $this->items)->draft, $this->draftOf($html));

        // The first submit button in tree order is the hidden Apply (P163).
        $first = $x->query($form . '//button[@type="submit"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $first);
        self::assertSame(['visually-hidden', 'do', 'apply', '-1', 'Apply changes'], [$first->getAttribute('class'), $first->getAttribute('name'), $first->getAttribute('value'), $first->getAttribute('tabindex'), $first->textContent]);

        self::assertSame('Fields and sections, in reading order', $this->attr($x, $form . '/ol[@class="form-rows"]', 'aria-label'));
        self::assertSame(['Details', 'Name', 'Brand', 'Serial number', 'Price', 'Room'], $this->texts($x, $form . '/ol/li//span[@class="form-row-name"]'));
        self::assertSame(['Text', 'Text', 'Text', 'Required', 'Text', 'Link to Rooms'], $this->texts($x, $form . '/ol/li/ol/li/div[@class="form-row-head"]/span[@class="form-row-kind"]'));
        $name = $this->f['Name'];
        self::assertSame('label[1-1]', $this->attr($x, '//input[@id="label-1-1"]', 'name'));
        self::assertSame('Label for Name', $this->attr($x, '//input[@id="label-1-1"]', 'aria-label'));
        self::assertSame('editor-help', $this->attr($x, '//input[@id="label-1-1"]', 'aria-describedby'));
        self::assertSame('Leave a label empty to use the field\'s name. Widths apply where the screen is wide enough; on narrow screens every field takes the whole width.', HtmlAssertions::text($html, '//p[@id="editor-help"]'));
        self::assertSame(['auto'], $this->values($x, '//select[@id="width-1-1"]/option[@selected]'));
        // A section child can always move: out of the section at its edges (M5 spec section 6.3); only the section itself cannot move here.
        self::assertSame(['up-1-1', 'down-1-1', 'hide-1-1'], $this->values($x, '//li[@id="row-1-1"]/div[@class="form-row-actions"]/button'));
        self::assertSame(['up-1-5', 'down-1-5', 'hide-1-5'], $this->values($x, '//li[@id="row-1-5"]/div[@class="form-row-actions"]/button'));
        self::assertSame(['ungroup-1'], $this->values($x, '//li[@id="row-1"]/div[@class="form-row-actions"]/button'), 'The only root row moves neither way.');
        self::assertSame(['Move up: Brand', 'Move down: Brand', 'Hide Brand'], $this->values($x, '//li[@id="row-1-2"]/div[@class="form-row-actions"]/button/@aria-label'));
        self::assertNotSame('', $name);

        self::assertSame(['add-section'], $this->values($x, $form . '/div[@class="form-actions-inline"]/button'));
        self::assertSame('Not on this form', HtmlAssertions::text($html, '//h2[@id="not-on-form-title"]'));
        self::assertSame('Every field of Items is on this form.', HtmlAssertions::text($html, '//section[@aria-labelledby="not-on-form-title"]/p'));
        self::assertSame(0, $x->query('//section[contains(@class, "form-report")]')->length, 'Nothing to report.');
        self::assertSame(['save'], $this->values($x, $form . '/div[@class="form-actions"]/button'));
        self::assertSame('Save the form', HtmlAssertions::text($html, $form . '/div[@class="form-actions"]/button'));
        self::assertSame($this->t('/fields'), $this->attr($x, $form . '/div[@class="form-actions"]/a', 'href'));
        self::assertSame('Cancel', HtmlAssertions::text($html, $form . '/div[@class="form-actions"]/a'));
        self::assertSame(0, $x->query('//*[@tabindex][not(@tabindex="-1")][not(@tabindex="0")]')->length);
    }

    public function testAnEditedFormWithEveryRowKind(): void
    {
        $this->saveSource($this->editedForm());
        $html = $this->page(self::ADMIN, $this->t('/form'));
        $x = $this->xpath($html);

        self::assertSame('This form has been arranged by hand. Fields added to the table later appear at the end of its first section.', HtmlAssertions::text($html, '//p[@class="notice notice-muted"]'));
        $form = '//form[@id="form-editor"]';
        self::assertSame(['form-row form-row-section', 'form-row', 'form-row', 'form-row', 'form-row', 'form-row', 'form-row form-row-section'], $this->values($x, $form . '/ol/li/@class'));

        // A field with a label, a width the editor does not offer, and a placement.
        self::assertSame('Cost', $this->attr($x, '//input[@id="label-1-1"]', 'value'));
        self::assertSame(['other'], $this->values($x, '//select[@id="width-1-1"]/option[@selected]'));
        self::assertSame('As in the source: 5 of 12 columns', HtmlAssertions::text($html, '//select[@id="width-1-1"]/option[@selected]'));
        self::assertSame('Placed by the source. Shown in reading order.', HtmlAssertions::text($html, '//li[@id="row-1-1"]/p[contains(@class, "form-row-note")]'));
        self::assertSame(['two-thirds'], $this->values($x, '//select[@id="width-1-2"]/option[@selected]'));
        // A missing slot.
        self::assertSame('form-row form-row-missing', $this->attr($x, '//li[@id="row-1-3"]', 'class'));
        self::assertSame(['up-1-3', 'down-1-3', 'remove-1-3'], $this->values($x, '//li[@id="row-1-3"]/div[@class="form-row-actions"]/button'));
        // Static rows, named by kind and summary; the hostile text is escaped.
        self::assertSame(self::HOSTILE, HtmlAssertions::text($html, '//li[@id="row-2"]/p[@class="form-row-summary"]'));
        self::assertSame('Remove Heading ' . self::HOSTILE, $this->attr($x, '//li[@id="row-2"]//button[@value="remove-2"]', 'aria-label'));
        self::assertSame('See the manual', HtmlAssertions::text($html, '//li[@id="row-3"]/p[@class="form-row-summary"]'));
        self::assertSame('A dividing line', HtmlAssertions::text($html, '//li[@id="row-4"]/p[@class="form-row-summary"]'));
        self::assertSame('Remove Divider', $this->attr($x, '//li[@id="row-4"]//button[@value="remove-4"]', 'aria-label'));
        self::assertSame('Description: A sketch', HtmlAssertions::text($html, '//li[@id="row-5"]/p[@class="form-row-summary"]'));
        self::assertSame('This picture is not shown: its file is not a picture attached to a record.', HtmlAssertions::text($html, '//li[@id="row-5"]/p[contains(@class, "form-row-note")]'));
        // An unnamed, empty section.
        self::assertSame('Section 2, no name', HtmlAssertions::text($html, '//li[@id="row-7"]//span[@class="form-row-name"]'));
        self::assertSame('This section is empty.', HtmlAssertions::text($html, '//li[@id="row-7"]/p[contains(@class, "form-row-note")]'));
        self::assertSame('Section name, section 2', $this->attr($x, '//input[@id="legend-7"]', 'aria-label'));

        // Not on this form: every field without a slot, the required one with its note.
        $list = '//section[@aria-labelledby="not-on-form-title"]/ul[@class="form-rows"]/li';
        self::assertSame(['Name', 'Serial number', 'Room'], $this->texts($x, $list . '//span[@class="form-row-name"]'));
        self::assertSame(['add-' . $this->f['Name'], 'add-' . $this->f['Serial number'], 'add-' . $this->f['Room']], $this->values($x, $list . '//button'));
        self::assertSame(['Add Name to the form', 'Add Serial number to the form', 'Add Room to the form'], $this->values($x, $list . '//button/@aria-label'));
        self::assertSame(['Required. While the form does not show it, records can be saved without it.'], $this->texts($x, $list . '/p'));

        // The report, without the not-shown list.
        $report = '//section[contains(@class, "form-report")]';
        self::assertSame(1, $x->query($report)->length);
        self::assertSame(0, $x->query($report . '/ul')->length);
        $lines = $this->texts($x, $report . '/p');
        self::assertCount(3, $lines);
        self::assertStringStartsWith('1 field element names a field that is not in Items.', $lines[0]);
        self::assertStringStartsWith('1 picture is not shown', $lines[1]);
        self::assertStringStartsWith('1 link has no text', $lines[2]);
    }

    public function testARefusedStoredForm(): void
    {
        $this->storeRaw('<div data-tiffin-form="1"><script>' . Corpus::MARKER . '</script></div>');
        $html = $this->page(self::ADMIN, $this->t('/form'));
        $x = $this->xpath($html);

        self::assertSame('The saved form could not be read', HtmlAssertions::text($html, '//div[@class="notice notice-warn"]/p[@class="notice-title"]'));
        self::assertStringNotContainsString(Corpus::MARKER, $html);
        self::assertSame(['Details', 'Name', 'Brand', 'Serial number', 'Price', 'Room'], $this->texts($x, '//form[@id="form-editor"]/ol/li//span[@class="form-row-name"]'));
    }

    // ------------------------------------------------------------------ actions (editor.md section 4)

    public function testMovesKeepTheFocusOnTheMovedRow(): void
    {
        $html = $this->change('down-1-1');
        $x = $this->xpath($html);
        self::assertSame(['Brand', 'Name'], array_slice($this->texts($x, '//form[@id="form-editor"]/ol/li/ol/li/div/span[@class="form-row-name"]'), 0, 2));
        self::assertSame(['down-1-2'], $this->values($x, '//*[@autofocus]'));
        self::assertSame('autofocus', $this->attr($x, '//*[@autofocus]', 'autofocus'));

        $html = $this->change('up-1-2', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame('Name', $this->texts($x, '//li[@id="row-1-1"]/div/span[@class="form-row-name"]')[0]);
        self::assertSame(['up-1-1'], $this->values($x, '//*[@autofocus]'));

        // The first child moved up leaves the section, to the root before it,
        // where it cannot move up again: the other move button takes the focus.
        $html = $this->change('up-1-1', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame('Name', $this->texts($x, '//li[@id="row-1"]/div/span[@class="form-row-name"]')[0]);
        self::assertSame(['down-1'], $this->values($x, '//*[@autofocus]'));
        self::assertSame(1, $x->query('//*[@autofocus]')->length);
    }

    public function testHideListsTheFieldAndFocusesTheNextRow(): void
    {
        $html = $this->change('hide-1-3');
        $x = $this->xpath($html);
        self::assertSame(['Serial number'], $this->texts($x, '//section[@aria-labelledby="not-on-form-title"]//span[@class="form-row-name"]'));
        self::assertSame(['Required. While the form does not show it, records can be saved without it.'], $this->texts($x, '//section[@aria-labelledby="not-on-form-title"]//p'));
        // Price is now 1-3; its first shown button is Move up.
        self::assertSame(['up-1-3'], $this->values($x, '//*[@autofocus]'));

        // Hiding the last row focuses "Add a section".
        $html = $this->change('hide-1-4', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame(['add-section'], $this->values($x, '//*[@autofocus]'));
    }

    public function testAddAppendsToTheFirstSection(): void
    {
        $html = $this->change('hide-1-3');
        $html = $this->change('add-' . $this->f['Serial number'], $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame(['Name', 'Brand', 'Price', 'Room', 'Serial number'], $this->texts($x, '//li[@id="row-1"]/ol/li/div/span[@class="form-row-name"]'));
        self::assertSame(['up-1-5'], $this->values($x, '//*[@autofocus]'));
        self::assertSame('Every field of Items is on this form.', HtmlAssertions::text($html, '//section[@aria-labelledby="not-on-form-title"]/p'));
    }

    public function testAddASectionFocusesItsNameBox(): void
    {
        $html = $this->change('add-section');
        $x = $this->xpath($html);
        self::assertSame('New section', $this->attr($x, '//input[@id="legend-2"]', 'value'));
        self::assertSame(['legend-2'], $this->values($x, '//*[@autofocus]/@id'));
        self::assertSame('This section is empty.', HtmlAssertions::text($html, '//li[@id="row-2"]/p[contains(@class, "form-row-note")]'));

        // Name it with Enter (Apply), then move Room down into it.
        $html = $this->change('apply', $this->draftOf($html), ['legend' => ['2' => 'Where it is']]);
        $x = $this->xpath($html);
        self::assertSame(0, $x->query('//*[@autofocus]')->length, 'Apply moves nothing and focuses nothing.');
        self::assertSame('Where it is', $this->attr($x, '//input[@id="legend-2"]', 'value'));
        $html = $this->change('down-1-5', $this->draftOf($html));
        $html = $this->change('down-2', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame(['Room'], $this->texts($x, '//li[@id="row-2"]/ol/li/div/span[@class="form-row-name"]'));
        self::assertSame(['down-2-1'], $this->values($x, '//*[@autofocus]'));

        // Remove the section: its rows stand in its place.
        $html = $this->change('ungroup-2', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame('Room', $this->texts($x, '//li[@id="row-2"]/div/span[@class="form-row-name"]')[0]);
        self::assertSame(['up-2'], $this->values($x, '//*[@autofocus]'));
    }

    public function testRemoveAStaticRowAndAMissingSlot(): void
    {
        $this->saveSource($this->editedForm());
        $draft = $this->ops->forms()->editor($this->admin(), $this->project, $this->items)->draft;
        $html = $this->change('remove-1-3', $draft);
        $x = $this->xpath($html);
        self::assertSame(0, $x->query('//li[contains(@class, "form-row-missing")]')->length);
        self::assertSame(['up-2'], $this->values($x, '//*[@autofocus]'), 'Focus on the next row: the heading after the section.');
        $html = $this->change('remove-4', $this->draftOf($html));
        $x = $this->xpath($html);
        self::assertSame(0, $x->query('//button[@value="remove-4"][@aria-label="Remove Divider"]')->length);
        self::assertSame(1, $x->query('//*[@autofocus]')->length);
    }

    public function testWidthsAndLabelsApplyOnEveryButton(): void
    {
        $html = $this->change('down-1-2', null, ['label' => ['1-1' => 'Item name'], 'width' => ['1-1' => 'two-thirds', '1-2' => 'half']]);
        $x = $this->xpath($html);
        self::assertSame('Item name', $this->attr($x, '//input[@id="label-1-1"]', 'value'));
        self::assertSame(['two-thirds'], $this->values($x, '//select[@id="width-1-1"]/option[@selected]'));
        self::assertSame(['half'], $this->values($x, '//select[@id="width-1-3"]/option[@selected]'), 'Brand moved with its width.');
        self::assertStringContainsString('data-tiffin-label="Item name"', $this->draftOf($html));
        self::assertNull($this->storedHtmlChanged(), 'Nothing is stored before Save.');
    }

    public function testAnUnknownActionIsApply(): void
    {
        foreach (['', 'explode', 'up-', 'up-0', 'add-NOTAKEY', 'up-1-1-1'] as $do) {
            $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => $do]);
            self::assertSame(200, $response->status, $do);
            $this->assertScreen($response->body);
            self::assertSame(0, $this->xpath($response->body)->query('//*[@autofocus]')->length, $do);
        }
    }

    public function testAStaleButtonIsAProblem(): void
    {
        foreach (['up-1', 'down-9', 'hide-7-1', 'remove-1-1', 'ungroup-1-1', 'add-' . $this->f['Name']] as $do) {
            $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => $do]);
            self::assertSame(422, $response->status, $do);
            $this->assertScreen($response->body);
            self::assertSame(['That change could not be made. The form is shown as it was.'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//li'), $do);
        }
    }

    public function testALabelProblemIs422AndMovesNothing(): void
    {
        $long = str_repeat('x', 61);
        $response = $this->post(self::ADMIN, $this->t('/form'), [
            'draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => 'down-1-1',
            'label' => ['1-2' => $long, '1-1' => self::HOSTILE],
        ]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertSame('#label-1-2', $this->attr($x, '//div[@class="error-summary"]//a', 'href'));
        self::assertStringStartsWith('Use 1 to 60 characters', HtmlAssertions::text($response->body, '//div[@class="error-summary"]//a'));
        self::assertSame($long, $this->attr($x, '//input[@id="label-1-2"]', 'value'), 'The typed text is kept.');
        self::assertSame('true', $this->attr($x, '//input[@id="label-1-2"]', 'aria-invalid'));
        self::assertSame('editor-help err-label-1-2', $this->attr($x, '//input[@id="label-1-2"]', 'aria-describedby'));
        self::assertStringStartsWith('Use 1 to 60 characters', HtmlAssertions::text($response->body, '//p[@id="err-label-1-2"]'));
        self::assertSame('Name', $this->texts($x, '//li[@id="row-1-1"]/div/span[@class="form-row-name"]')[0], 'No move was made.');
        self::assertSame(0, $x->query('//*[@autofocus]')->length);
    }

    public function testALegendProblemLinksToTheNameBox(): void
    {
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => 'save', 'legend' => ['1' => str_repeat('y', 101)]]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertSame('#legend-1', $this->attr($this->xpath($response->body), '//div[@class="error-summary"]//a', 'href'));
        self::assertNull($this->storedHtmlChanged());
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function tamperedDrafts(): iterable
    {
        foreach (Corpus::all() as $id => $case) {
            if ($case['verdict'] === 'refuse' && in_array($case['group'], ['active', 'attributes', 'links', 'declarations', 'structure'], true)) {
                yield 'corpus ' . $id => [$case['text']];
            }
        }
        yield 'an array' => [['<div data-tiffin-form="1"></div>']];
        yield 'empty' => [''];
        yield 'truncated' => ["<div data-tiffin-form=\"1\">\n  <fieldset data-tiffin-section=\"details\">\n"];
        yield 'not a form' => ['hello'];
    }

    #[DataProvider('tamperedDrafts')]
    public function testATamperedDraftIs422WithTheSavedForm(mixed $draft): void
    {
        foreach (['apply', 'save', 'down-1-1'] as $do) {
            $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $draft, 'base' => $this->base(), 'do' => $do]);
            self::assertSame(422, $response->status, $do);
            $this->assertScreen($response->body);
            $x = $this->xpath($response->body);
            self::assertSame(['The form on this page could not be read, so the saved form is shown again. Your unsaved changes were not kept.'], $this->texts($x, '//div[@class="error-summary"]//li'));
            self::assertSame(0, $x->query('//div[@class="error-summary"]//a')->length);
            self::assertStringNotContainsString(Corpus::MARKER, $response->body);
            self::assertSame($this->standardDraft(), $this->draftOf($response->body));
        }
        self::assertNull($this->storedHtmlChanged());
    }

    public function testArrayShapedInputsNeverFail(): void
    {
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->standardDraft(), 'base' => ['x'], 'do' => ['save'], 'label' => 'x', 'legend' => [['x']], 'width' => ['1-1' => ['half']]]);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);
    }

    // ------------------------------------------------------------------ save (editor.md section 4)

    public function testSaveAndItsNotices(): void
    {
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => 'save', 'width' => ['1-1' => 'half']]);
        self::assertSame(303, $response->status);
        self::assertSame($this->t('/form'), $response->headers['Location'] ?? null);
        self::assertSame(['key' => 'notice.form_saved', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        self::assertStringContainsString('data-tiffin-span="6"', (string) $this->storedHtmlChanged());

        $html = $this->page(self::ADMIN, $this->t('/form'));
        self::assertSame('The form for Items has been saved.', HtmlAssertions::text($html, '//*[@role="status"]'));
        self::assertSame(['half'], $this->values($this->xpath($html), '//select[@id="width-1-1"]/option[@selected]'));

        // Saving again without a change.
        $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->draftOf($html), 'base' => $this->baseOf($html), 'do' => 'save']);
        self::assertSame(['key' => 'notice.form_saved_unchanged', 'params' => []], $this->services->flash[self::ADMIN]);

        // Back to automatic: the same as the standard form.
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->draftOf($html), 'base' => $this->baseOf($html), 'do' => 'save', 'width' => ['1-1' => 'auto']]);
        self::assertSame(303, $response->status);
        self::assertSame(['key' => 'notice.form_saved_standard', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        $html = $this->page(self::ADMIN, $this->t('/form'));
        self::assertSame('This is the standard form. It follows the table\'s fields by itself until you save a change.', HtmlAssertions::text($html, '//p[@class="notice notice-muted"]'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function drafts(): iterable
    {
        yield 'T35' => ['T35'];
        yield 'T15' => ['T15'];
        yield 'edited' => ['edited'];
    }

    #[DataProvider('drafts')]
    public function testTheHiddenDraftGivesTheSameBytesBack(string $which): void
    {
        $text = $which === 'edited' ? $this->editedForm() : Corpus::all()[$which]['text'];
        $this->saveSource($text);
        $stored = $this->storedHtml();
        $html = $this->page(self::ADMIN, $this->t('/form'));
        $draft = $this->draftOf($html);
        self::assertSame($stored, $draft);

        // A browser sends the line breaks as CR LF.
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => str_replace("\n", "\r\n", $draft), 'base' => $this->baseOf($html), 'do' => 'save']);
        self::assertSame(303, $response->status);
        self::assertSame(['key' => 'notice.form_saved_unchanged', 'params' => []], $this->services->flash[self::ADMIN]);
        self::assertSame($stored, $this->storedHtml());
    }

    public function testSaveAfterSomeoneElseSaved(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/form'));
        $this->saveSource($this->editedForm());
        $stored = $this->storedHtml();

        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->draftOf($html), 'base' => $this->baseOf($html), 'do' => 'save', 'width' => ['1-1' => 'half']]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertSame(['Someone saved this form after you opened it. Save again to replace their version with yours.'], $this->texts($this->xpath($response->body), '//div[@class="error-summary"]//li'));
        self::assertSame($stored, $this->storedHtml());
        self::assertNotSame($this->baseOf($html), $this->baseOf($response->body));
        self::assertSame(['half'], $this->values($this->xpath($response->body), '//select[@id="width-1-1"]/option[@selected]'), 'The working copy is kept.');

        $again = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $this->draftOf($response->body), 'base' => $this->baseOf($response->body), 'do' => 'save']);
        self::assertSame(303, $again->status);
        self::assertNotSame($stored, $this->storedHtml());
    }

    // ------------------------------------------------------------------ reset (reset.md)

    public function testTheResetConfirmation(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/form/reset'));
        $x = $this->xpath($html);
        self::assertSame('page page-form page-form-narrow', $this->attr($x, '//main', 'class'));
        self::assertSame(['Projects', 'Home Inventory', 'Items', 'Form', 'Standard form'], $this->crumbs($x));
        self::assertSame($this->t('/form'), $this->attr($x, '//nav[@class="breadcrumb"]/a[4]', 'href'));
        self::assertSame('Go back to the standard form?', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('page', $this->attr($x, '//nav[@class="form-tabs"]//a[text()="Back to the standard form"]', 'aria-current'));
        self::assertSame('The form for Items is already the standard form.', HtmlAssertions::text($html, '//main//p[@class="notice notice-muted"]'));
        self::assertSame([$this->t('/form')], $this->values($x, '//main//a[@class="button button-large"]/@href'));
        self::assertSame(0, $x->query('//main//form')->length);

        $this->saveSource($this->editedForm());
        $html = $this->page(self::ADMIN, $this->t('/form/reset'));
        $x = $this->xpath($html);
        self::assertStringStartsWith('The form for Items will be replaced by the standard form', HtmlAssertions::text($html, '//main/p[1]'));
        self::assertSame($this->t('/form/download'), $this->attr($x, '//main//a[text()="Download the current form"]', 'href'));
        self::assertSame(['post', $this->t('/form/reset')], [$this->attr($x, '//main//form', 'method'), $this->attr($x, '//main//form', 'action')]);
        self::assertSame('Use the standard form', HtmlAssertions::text($html, '//main//form//button[contains(@class, "button-danger")]'));
        self::assertSame($this->t('/form'), $this->attr($x, '//main//form//a', 'href'));

        $response = $this->post(self::ADMIN, $this->t('/form/reset'), []);
        self::assertSame(303, $response->status);
        self::assertSame($this->t('/form'), $response->headers['Location'] ?? null);
        self::assertSame(['key' => 'notice.form_reset', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
        $html = $this->page(self::ADMIN, $this->t('/form'));
        self::assertSame('The form for Items is the standard form again.', HtmlAssertions::text($html, '//*[@role="status"]'));

        $this->post(self::ADMIN, $this->t('/form/reset'), []);
        self::assertSame(['key' => 'notice.form_reset_unchanged', 'params' => ['table' => 'Items']], $this->services->flash[self::ADMIN]);
    }

    // ------------------------------------------------------------------ refusals (T-WEB-75)

    public function testPostsWithoutAValidTokenOrFromAnotherOriginAreRefused(): void
    {
        $this->saveSource($this->editedForm());
        $stored = $this->storedHtml();
        $save = ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => 'save'];
        foreach ([$this->t('/form') => $save, $this->t('/form/reset') => []] as $uri => $post) {
            $missing = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => self::ADMIN]))[0];
            self::assertSame(403, $missing->status, 'No CSRF token: ' . $uri);
            $wrong = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $uri, $post + ['_csrf' => str_repeat('f', 64)], ['tdb_session' => self::ADMIN]))[0];
            self::assertSame(403, $wrong->status, 'Wrong CSRF token: ' . $uri);
            $server = ['HTTP_HOST' => 'example.test', 'HTTP_ORIGIN' => 'http://attacker.example'];
            $cross = Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $uri, $post + ['_csrf' => FakeServices::SESSION_CSRF], ['tdb_session' => self::ADMIN], $server))[0];
            self::assertSame(403, $cross->status, 'Cross-origin: ' . $uri);
        }
        self::assertSame($stored, $this->storedHtml(), 'Nothing was stored.');
    }

    public function testAMemberIsRefusedAndAnUngrantedPersonSeesNothing(): void
    {
        $this->saveSource($this->editedForm());
        $stored = $this->storedHtml();
        $routes = [['GET', '/form', []], ['POST', '/form', ['draft' => $this->standardDraft(), 'base' => $this->base(), 'do' => 'save']], ['GET', '/form/reset', []], ['POST', '/form/reset', []]];
        foreach ([404, 403] as $expected) {
            if ($expected === 403) {
                $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
            }
            foreach ($routes as [$method, $path, $post]) {
                $response = $method === 'GET' ? $this->request(self::MEMBER, 'GET', $this->t($path)) : $this->post(self::MEMBER, $this->t($path), $post);
                self::assertSame($expected, $response->status, $method . ' ' . $path);
                self::assertStringNotContainsString('form-editor', $response->body);
            }
        }
        self::assertSame($stored, $this->storedHtml());
    }

    public function testUnknownTablesAndProjectsAreNotFound(): void
    {
        foreach (['/projects/' . $this->project . '/tables/zzzzzzzzzzzz/form', '/projects/' . $this->project . '/tables/BAD/form/reset', '/projects/zzzzzzzzzzzz/tables/' . $this->items . '/form'] as $uri) {
            self::assertSame(404, $this->request(self::ADMIN, 'GET', $uri)->status, $uri);
            self::assertSame(404, $this->post(self::ADMIN, $uri, ['do' => 'save'])->status, $uri);
        }
    }

    // ------------------------------------------------------------------ helpers

    /** A hand-written form holding every row kind (synthetic text). */
    private function editedForm(): string
    {
        return '<div data-tiffin-form="1">' . "\n"
            . '<fieldset data-tiffin-section="details"><legend>Details</legend>' . "\n"
            . '<div data-tiffin-field="' . $this->f['Price'] . '" data-tiffin-label="Cost" data-tiffin-span="5" data-tiffin-col="2"></div>' . "\n"
            . '<div data-tiffin-field="' . $this->f['Brand'] . '" data-tiffin-span="8"></div>' . "\n"
            . '<div data-tiffin-field="zzzzzzzzzzzz"></div>' . "\n"
            . '</fieldset>' . "\n"
            . '<h2>' . htmlspecialchars(self::HOSTILE, ENT_QUOTES | ENT_XML1) . '</h2>' . "\n"
            . '<p>See <a href="https://example.com/manual">the manual</a><a href="https://example.com/empty"></a></p>' . "\n"
            . '<hr/>' . "\n"
            . '<img src="attachment:999" alt="A sketch"/>' . "\n"
            . '<p>Text</p>' . "\n"
            . '<fieldset></fieldset>' . "\n"
            . '</div>' . "\n";
    }

    private function saveSource(string $text): void
    {
        $forms = $this->ops->forms();
        $base = $forms->source($this->admin(), $this->project, $this->items)->base;
        $screen = $forms->source($this->admin(), $this->project, $this->items, ['text' => $text, 'base' => $base, 'do' => 'save']);
        self::assertSame([], $screen->problems, 'The fixture form is accepted.');
        self::assertNotNull($screen->saved);
    }

    /** Stores a text as the default form without the parser, as a damaged file would hold it. */
    private function storeRaw(string $html): void
    {
        $db = $this->core->projectDb($this->project);
        $table = $this->items;
        $db->write(static function ($db) use ($html, $table): void {
            $db->run('UPDATE "_tiffin_forms" SET "html" = ?, "generated" = 0 WHERE "table_key" = ?', [$html, $table]);
        });
    }

    private function storedHtml(): string
    {
        return $this->ops->forms()->download($this->admin(), $this->project, $this->items)->text;
    }

    /** The stored text when it differs from the standard form, else null. */
    private function storedHtmlChanged(): ?string
    {
        $stored = $this->storedHtml();

        return $stored === $this->standardDraft() ? null : $stored;
    }

    private function standardDraft(): string
    {
        return $this->standard;
    }

    private function base(): string
    {
        return $this->ops->forms()->editor($this->admin(), $this->project, $this->items)->base;
    }

    /**
     * Posts a change from the editor and returns the page (200).
     *
     * @param array<string, mixed> $inputs
     */
    private function change(string $do, ?string $draft = null, array $inputs = []): string
    {
        $response = $this->post(self::ADMIN, $this->t('/form'), ['draft' => $draft ?? $this->standardDraft(), 'base' => $this->base(), 'do' => $do] + $inputs);
        self::assertSame(200, $response->status, $do);
        $this->assertScreen($response->body);

        return $response->body;
    }

    /**
     * The hidden draft's value as a browser reads it: an HTML parser,
     * which keeps line feeds in attribute values (an XML parser would
     * normalize them to spaces).
     */
    private function draftOf(string $html): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $node = (new DOMXPath($document))->query('//form[@id="form-editor"]/input[@name="draft"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node->getAttribute('value');
    }

    private function baseOf(string $html): string
    {
        return (string) $this->attr($this->xpath($html), '//form[@id="form-editor"]/input[@name="base"]', 'value');
    }

    private function admin(): Actor
    {
        return $this->core->actor('admin');
    }

    private function signedIn(string $username): SignedIn
    {
        $actor = $this->core->actor($username);

        return new SignedIn(
            $actor,
            new SessionRecord(str_repeat('0', 64), $actor->userId, FakeServices::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            false,
        );
    }

    private function t(string $path): string
    {
        return '/projects/' . $this->project . '/tables/' . $this->items . $path;
    }

    private function request(string $token, string $method, string $uri): Response
    {
        return Fixtures::handle($this->kernel, Fixtures::fullRequest($method, $uri, [], ['tdb_session' => $token]))[0];
    }

    /** @param array<string, mixed> $post */
    private function post(string $token, string $uri, array $post): Response
    {
        $post['_csrf'] = FakeServices::SESSION_CSRF;

        return Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $uri, $post, ['tdb_session' => $token]))[0];
    }

    private function page(string $token, string $uri): string
    {
        $response = $this->request($token, 'GET', $uri);
        self::assertSame(200, $response->status, $uri);
        $this->assertScreen($response->body);

        return $response->body;
    }

    private function assertScreen(string $html): void
    {
        HtmlAssertions::assertShell($html, Fixtures::SOURCE_URL, 'TiffinDB, free software under AGPL-3.0-or-later', 'Source code');
        HtmlAssertions::assertNoInlineCode($html, Fixtures::SOURCE_URL);
        HtmlAssertions::assertForm($html);
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private function attr(DOMXPath $x, string $query, string $name): ?string
    {
        $node = $x->query($query)->item(0);

        return $node instanceof DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
    }

    /** @return list<string> */
    private function crumbs(DOMXPath $x): array
    {
        return $this->texts($x, '//nav[@class="breadcrumb"]/*[not(@aria-hidden)]');
    }

    /** @return list<string> the `value` of each element, or the text of each attribute node */
    private function values(DOMXPath $x, string $query): array
    {
        $values = [];
        foreach ($x->query($query) as $node) {
            $values[] = $node instanceof DOMElement ? $node->getAttribute('value') : (string) $node->nodeValue;
        }

        return $values;
    }

    /** @return list<string> */
    private function texts(DOMXPath $x, string $query): array
    {
        $texts = [];
        foreach ($x->query($query) as $node) {
            $texts[] = trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        }

        return $texts;
    }
}
