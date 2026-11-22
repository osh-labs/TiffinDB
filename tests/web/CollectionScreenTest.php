<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Schema\Cardinality;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Web\Fixtures\FakeServices;
use TiffinDB\Tests\Web\Fixtures\Fixtures;
use TiffinDB\Web\Controller\CollectionController;
use TiffinDB\Web\Controller\RecordListController;
use TiffinDB\Web\Kernel;
use TiffinDB\Web\Response;

require_once __DIR__ . '/fixtures/Fixtures.php';

/**
 * T-WEB-57 (M4-WP7): the collection screens of
 * docs/design/m4/collections.md through the kernel with the real templates
 * and strings and real core operations over a CoreFixture instance: the
 * editor (new, existing, field change, add, remove, preview, broken rules,
 * 422 with every problem linked from the summary), save, the collection
 * view (rules applied, find and sort kept, broken-rules notice), and
 * delete; hostile names and values escaped; posted arrays of the wrong
 * shape never give 500; ungranted people get 404. Synthetic data only.
 */
#[CoversClass(CollectionController::class)]
#[CoversClass(RecordListController::class)]
final class CollectionScreenTest extends TestCase
{
    private const ADMIN = FakeServices::TOKEN;
    private const MEMBER = FakeServices::OTHER_TOKEN;

    private CoreFixture $core;
    private Operations $ops;
    private FakeServices $services;
    private Kernel $kernel;
    private string $project;
    private string $items;
    private string $rooms;
    /** @var array<string, string> field name => key */
    private array $f = [];
    /** @var array<string, int> record name => id */
    private array $r = [];
    /** @var array<string, string> choice label => key (Category) */
    private array $choice = [];

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
        $this->rooms = $schema->createTable($admin, $this->project, 'Rooms', 'room', true)->key;
        $this->f['Room name'] = $schema->design($admin, $this->project, $this->rooms)->fields[0]->field->key;
        $this->f['Room'] = $schema->addRelationField($admin, $this->project, $this->items, 'Room', $this->rooms, Cardinality::One, Cardinality::Many, 'Items', false, '')->key;
        $this->addField('Category', 'choice', ['choices' => ['Appliance', 'Cookware', 'Tool']]);
        $this->addField('Purchased', 'date');
        $this->addField('Value', 'currency', ['code' => 'USD', 'minor_digits' => 2]);
        $this->addField('Condition', 'rating', ['max' => 5]);
        $this->addField('Receipt', 'attachment');
        $this->addField('Under warranty', 'checkbox');
        $this->addField('Length', 'duration');
        foreach ($schema->design($admin, $this->project, $this->items)->fields as $summary) {
            if ($summary->field->key === $this->f['Category']) {
                foreach ($summary->field->options['choices'] as $choice) {
                    $this->choice[$choice['label']] = $choice['key'];
                }
            }
        }

        $this->r['Kitchen'] = $this->core->addRecord($this->project, $this->rooms, [$this->f['Room name'] => 'Kitchen']);
        $this->r['Garage'] = $this->core->addRecord($this->project, $this->rooms, [$this->f['Room name'] => 'Garage']);
        $this->r['Stand mixer'] = $this->add(['Name' => 'Stand mixer', 'Category' => 'Appliance', 'Purchased' => '2025-03-14', 'Value' => '329.00', 'Condition' => '5', 'Under warranty' => '1']);
        $this->r['Dutch oven'] = $this->add(['Name' => 'Dutch oven', 'Category' => 'Cookware', 'Purchased' => '2022-12-25', 'Value' => '95.00', 'Condition' => '4']);
        $this->r['Cordless drill'] = $this->add(['Name' => 'Cordless drill', 'Category' => 'Tool', 'Purchased' => '2023-11-02', 'Value' => '149.00', 'Under warranty' => '1']);
        $this->ops->records()->setLinks($admin, $this->project, $this->items, (string) $this->r['Stand mixer'], $this->f['Room'], [$this->r['Kitchen']]);
    }

    protected function tearDown(): void
    {
        $this->core->remove();
    }

    // ------------------------------------------------------------------ editor (section 3)

    public function testNewEditor(): void
    {
        $html = $this->page(self::ADMIN, $this->t('/collections/new'));
        $x = $this->xpath($html);

        self::assertSame('New collection - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'New collection'], $this->crumbs($x));
        self::assertSame($this->p(''), $this->attr($x, '//nav[@class="breadcrumb"]/a[1]', 'href'));
        self::assertSame($this->t(''), $this->attr($x, '//nav[@class="breadcrumb"]/a[2]', 'href'));
        self::assertSame('New collection', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('A collection is a saved search. It updates by itself as records change.', HtmlAssertions::text($html, '//main//h1/following-sibling::p[@class="lead"]'));
        self::assertSame(0, $x->query('//nav[@class="sidebar"]')->length, 'The editor has no sidebar.');
        self::assertSame(0, $x->query('//script')->length, 'No script: only the Preview button reloads (WCAG 3.2.2, P181).');

        $form = '//form[@id="collection-form"]';
        self::assertSame(['post', $this->t('/collections'), '1'], [$this->attr($x, $form, 'method'), $this->attr($x, $form, 'action'), $this->attr($x, $form, 'data-collection-editor')]);
        // The first submit button in tree order is the hidden Preview (P163, keyboard check 4).
        $first = $x->query($form . '//button[@type="submit"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $first);
        self::assertSame(['default-action', 'action', 'preview', '-1'], [$first->getAttribute('class'), $first->getAttribute('name'), $first->getAttribute('value'), $first->getAttribute('tabindex')]);
        self::assertSame(1, $x->query('//*[@tabindex="-1"][not(self::main)][not(self::div[@class="error-summary"])]')->length, 'Only the hidden default button leaves the tab order.');

        self::assertSame('Collection name', HtmlAssertions::text($html, '//label[@for="f-name"]'));
        self::assertSame(['text', 'name', '60', ''], [$this->attr($x, '//input[@id="f-name"]', 'type'), $this->attr($x, '//input[@id="f-name"]', 'name'), $this->attr($x, '//input[@id="f-name"]', 'maxlength'), $this->attr($x, '//input[@id="f-name"]', 'value')]);
        self::assertSame('Show items that match', HtmlAssertions::text($html, '//fieldset[@class="field-group"]/legend[@class="legend-plain"]'));
        self::assertSame(['All of these rules', 'Any of these rules'], $this->texts($x, '//div[@class="match-row"]/label[@class="check"]'));
        self::assertSame(['all'], $this->values($x, '//input[@name="match"][@checked]'));

        // One empty rule.
        self::assertSame(1, $x->query('//div[@class="rule-list"]/fieldset[@class="rule-row"]')->length);
        self::assertSame('Rule 1', HtmlAssertions::text($html, '//fieldset[@class="rule-row"]/legend'));
        $field = '//select[@id="f-rule-1-field"]';
        self::assertSame(['rules[1][field]', 'Field for rule 1', '1'], [$this->attr($x, $field, 'name'), $this->attr($x, $field, 'aria-label'), $this->attr($x, $field, 'data-rule-field')]);
        self::assertSame(['Choose a field', 'Name', 'Room', 'Category', 'Purchased', 'Value', 'Condition', 'Under warranty', 'Length'], $this->texts($x, $field . '/option'), 'Fields usable in rules, in field order; no attachment field.');
        self::assertSame([''], $this->values($x, $field . '/option[@selected] | ' . $field . '/option[1]'));
        self::assertSame(0, $x->query('//select[@name="rules[1][test]"] | //*[@name="rules[1][value]"]')->length, 'No test until a field is chosen.');
        self::assertSame(['remove-1', 'Remove rule 1', 'Remove'], [$this->attr($x, '//button[@aria-label="Remove rule 1"]', 'value'), $this->attr($x, '//fieldset[@class="rule-row"]/button', 'aria-label'), HtmlAssertions::text($html, '//fieldset[@class="rule-row"]/button')]);
        self::assertSame('Add a rule', HtmlAssertions::text($html, '//div[@class="rule-list"]//button[@value="add"]'));

        // Sort.
        self::assertSame('Sort by', HtmlAssertions::text($html, '//label[@for="f-sort"]'));
        $sorts = $this->texts($x, '//select[@id="f-sort"]/option');
        self::assertSame('As the table', $sorts[0]);
        self::assertSame(['Name, A to Z', 'Name, Z to A'], array_slice($sorts, 1, 2));
        self::assertContains('Purchased, newest first', $sorts);
        self::assertSame([''], $this->values($x, '//select[@id="f-sort"]/option[1]'));

        // Actions.
        self::assertSame(['Save collection', 'Preview', 'Cancel'], $this->texts($x, '//div[@class="collection-actions"]/*'));
        self::assertSame(['save', 'preview'], $this->values($x, '//div[@class="collection-actions"]/button'));
        self::assertSame('1', $this->attr($x, '//div[@class="collection-actions"]/button[@value="preview"]', 'data-preview'));
        self::assertSame($this->t(''), $this->attr($x, '//div[@class="collection-actions"]/a', 'href'));
        self::assertSame(0, $x->query('//a[normalize-space(.)="Delete this collection"]')->length);

        // Preview: every record of the table.
        self::assertSame('3 items match', HtmlAssertions::text($html, '//section[@class="collection-preview"][@aria-labelledby="c-res"]/h2[@id="c-res"]'));
        self::assertSame(['Name', 'Room', 'Category', 'Purchased'], $this->texts($x, '//section[@class="collection-preview"]//thead/tr/th'));
        self::assertSame(['Cordless drill', 'Dutch oven', 'Stand mixer'], $this->texts($x, '//section[@class="collection-preview"]//tbody/tr/th/a'));
        self::assertSame($this->t('/records/' . $this->r['Stand mixer']), $this->attr($x, '//section[@class="collection-preview"]//a[normalize-space(.)="Stand mixer"]', 'href'), 'No collection state before it is saved.');
        $this->assertOrder($x, ['//input[@id="f-name"]', '//input[@name="match"]', '//select[@id="f-rule-1-field"]', '//button[@value="add"]', '//select[@id="f-sort"]', '//button[@value="save"]', '//section[@class="collection-preview"]']);
    }

    public function testTheEditorScriptIsNotRequested(): void
    {
        // P181 (WCAG 3.2.2): no change of a control submits the form; only
        // the Preview buttons (and Enter in a text box) reload the page.
        foreach ([$this->t('/collections/new'), $this->c($this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]), '/edit')] as $uri) {
            $html = $this->page(self::ADMIN, $uri);
            self::assertStringNotContainsString('collection-editor.js', $html, $uri);
            self::assertSame(0, $this->xpath($html)->query('//script')->length, $uri);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string, string, string|null}>
     */
    public static function fieldTypes(): iterable
    {
        yield 'text' => ['Name', 'contains', ['contains', 'is exactly', 'is empty', 'is not empty'], 'input', 'Text for rule 1', 'text'];
        yield 'choice' => ['Category', 'is', ['is', 'is not chosen'], 'select', 'Value for rule 1', null];
        yield 'date' => ['Purchased', 'on', ['is on', 'is before', 'is after', 'is empty'], 'input', 'Date for rule 1', 'date'];
        yield 'currency' => ['Value', 'is', ['is', 'is less than', 'is more than', 'is empty'], 'input', 'Amount for rule 1', 'text'];
        yield 'rating' => ['Condition', 'is', ['is', 'is less than', 'is more than', 'is empty'], 'select', 'Rating for rule 1', null];
        yield 'duration' => ['Length', 'is', ['is', 'is less than', 'is more than', 'is empty'], 'input', 'Length of time for rule 1', 'text'];
        yield 'relation' => ['Room', 'links-to', ['links to', 'has no links'], 'select', 'Record for rule 1', null];
    }

    /** A field change takes effect on any press: the row's tests and value control match the field. */
    #[DataProvider('fieldTypes')]
    public function testFieldChangeShowsTheFieldsTestsAndValueControl(string $field, string $first, array $tests, string $element, string $label, ?string $type): void
    {
        $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => 'preview', 'name' => '', 'match' => 'all', 'rules' => [1 => ['field' => $this->f[$field], 'test' => 'not-a-test', 'value' => '']]]);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);

        self::assertSame([$this->f[$field]], $this->values($x, '//select[@id="f-rule-1-field"]/option[@selected]'));
        self::assertSame('Test for rule 1', $this->attr($x, '//select[@name="rules[1][test]"]', 'aria-label'));
        self::assertSame($tests, $this->texts($x, '//select[@name="rules[1][test]"]/option'));
        self::assertSame([$first], $this->values($x, '//select[@name="rules[1][test]"]/option[@selected]'), 'An unfitting test resets to the field\'s first.');
        $name = $field === 'Room' ? 'rules[1][record]' : 'rules[1][value]';
        $control = $x->query('//fieldset[@class="rule-row"]/*[@name="' . $name . '"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $control, $field);
        self::assertSame($element, $control->nodeName);
        self::assertSame($label, $control->getAttribute('aria-label'));
        if ($type !== null) {
            self::assertSame($type, $control->getAttribute('type'));
        }
        if ($field === 'Value') {
            self::assertSame('decimal', $control->getAttribute('inputmode'));
        }
        if ($field === 'Category') {
            self::assertSame(['', ...array_values($this->choice)], $this->values($x, '//select[@name="rules[1][value]"]/option'), 'Choice values are keys.');
            self::assertSame(['Choose one', 'Appliance', 'Cookware', 'Tool'], $this->texts($x, '//select[@name="rules[1][value]"]/option'));
            self::assertSame([''], $this->values($x, '//select[@name="rules[1][value]"]/option[@selected]'), 'Nothing chosen yet: the empty option.');
        }
        if ($field === 'Condition') {
            self::assertSame(['1', '2', '3', '4', '5'], $this->texts($x, '//select[@name="rules[1][value]"]/option'));
        }
        if ($field === 'Room') {
            self::assertSame(['Choose one', 'Garage', 'Kitchen'], $this->texts($x, '//select[@name="rules[1][record]"]/option'));
            self::assertSame(['', (string) $this->r['Garage'], (string) $this->r['Kitchen']], $this->values($x, '//select[@name="rules[1][record]"]/option'));
        }
        if ($field === 'Length') {
            self::assertSame('Hours and minutes, for example 1:30.', HtmlAssertions::text($response->body, '//fieldset[@class="rule-row"]/p[@class="help rule-problem"]'));
        }
    }

    public function testTestsWithoutAValueHaveNoValueControl(): void
    {
        foreach ([['Name', 'empty'], ['Name', 'not-empty'], ['Under warranty', 'ticked'], ['Under warranty', 'not-ticked'], ['Room', 'empty'], ['Category', 'empty']] as [$field, $test]) {
            $html = $this->editorPost(['rules' => [1 => ['field' => $this->f[$field], 'test' => $test]]]);
            $x = $this->xpath($html);
            self::assertSame([$test], $this->values($x, '//select[@name="rules[1][test]"]/option[@selected]'), "{$field} {$test}");
            self::assertSame(0, $x->query('//*[@name="rules[1][value]"] | //*[@name="rules[1][record]"]')->length, "{$field} {$test}");
        }
        $x = $this->xpath($this->editorPost(['rules' => [1 => ['field' => $this->f['Under warranty']]]]));
        self::assertSame(['is ticked', 'is not ticked'], $this->texts($x, '//select[@name="rules[1][test]"]/option'));
    }

    public function testAddAndRemoveRules(): void
    {
        $rules = [1 => ['field' => $this->f['Name'], 'test' => 'contains', 'value' => 'mixer'], 2 => ['field' => $this->f['Under warranty'], 'test' => 'ticked']];
        $html = $this->editorPost(['action' => 'add', 'name' => 'Draft', 'match' => 'any', 'rules' => $rules]);
        $x = $this->xpath($html);
        self::assertSame(['Rule 1', 'Rule 2', 'Rule 3'], $this->texts($x, '//fieldset[contains(@class, "rule-row")]/legend'));
        self::assertSame(['Draft'], $this->values($x, '//input[@id="f-name"]'));
        self::assertSame(['any'], $this->values($x, '//input[@name="match"][@checked]'));
        self::assertSame(['mixer'], $this->values($x, '//input[@name="rules[1][value]"]'));
        self::assertSame([''], $this->values($x, '//select[@id="f-rule-3-field"]/option[1]'));
        self::assertSame(0, $x->query('//select[@id="f-rule-3-field"]/option[@selected]')->length);
        self::assertSame(['Field for rule 3', 'Remove rule 3'], [$this->attr($x, '//select[@id="f-rule-3-field"]', 'aria-label'), $this->attr($x, '//button[@value="remove-3"]', 'aria-label')]);
        self::assertSame(0, $x->query('//div[contains(@class, "notice")]')->length, 'No notice after Add.');

        // Remove rule 1: the old rule 2 becomes rule 1.
        $html = $this->editorPost(['action' => 'remove-1', 'name' => 'Draft', 'rules' => $rules]);
        $x = $this->xpath($html);
        self::assertSame(['Rule 1'], $this->texts($x, '//fieldset[contains(@class, "rule-row")]/legend'));
        self::assertSame([$this->f['Under warranty']], $this->values($x, '//select[@id="f-rule-1-field"]/option[@selected]'));
        self::assertSame('2 items match', HtmlAssertions::text($html, '//h2[@id="c-res"]'));

        // Ten rules: no Add button; Add does nothing more.
        $ten = array_fill(1, 10, ['field' => $this->f['Under warranty'], 'test' => 'ticked']);
        $x = $this->xpath($this->editorPost(['action' => 'add', 'rules' => $ten]));
        self::assertSame(10, $x->query('//fieldset[contains(@class, "rule-row")]')->length);
        self::assertSame(0, $x->query('//button[@value="add"]')->length);
        $x = $this->xpath($this->editorPost(['action' => 'preview', 'rules' => array_fill(1, 14, ['field' => $this->f['Under warranty'], 'test' => 'ticked'])]));
        self::assertSame(10, $x->query('//fieldset[contains(@class, "rule-row")]')->length, 'At most 10 rows are read.');

        // Removing the only rule leaves none; the preview lists every record.
        $html = $this->editorPost(['action' => 'remove-1', 'rules' => [1 => $rules[1]]]);
        self::assertSame(0, $this->xpath($html)->query('//fieldset[contains(@class, "rule-row")]')->length);
        self::assertSame('3 items match', HtmlAssertions::text($html, '//h2[@id="c-res"]'));
    }

    public function testPreviewCountsAndShowsTheFirstFive(): void
    {
        $html = $this->editorPost(['rules' => [1 => ['field' => $this->f['Under warranty'], 'test' => 'ticked']], 'sort' => $this->f['Purchased'] . '.desc']);
        $x = $this->xpath($html);
        self::assertSame('2 items match', HtmlAssertions::text($html, '//h2[@id="c-res"]'));
        self::assertSame(0, $x->query('//span[@class="preview-count-note"]')->length);
        self::assertSame(['Stand mixer', 'Cordless drill'], $this->texts($x, '//section[@class="collection-preview"]//tbody/tr/th/a'));
        self::assertSame(['descending'], $this->values($x, '//section[@class="collection-preview"]//th[@aria-sort]/@aria-sort'));
        self::assertSame('Purchased (newest first)', HtmlAssertions::text($html, '//section[@class="collection-preview"]//th[@aria-sort]'));
        self::assertSame([$this->f['Purchased'] . '.desc'], $this->values($x, '//select[@id="f-sort"]/option[@selected]'));

        $html = $this->editorPost(['rules' => [1 => ['field' => $this->f['Name'], 'test' => 'is', 'value' => 'stand MIXER']]]);
        self::assertSame('1 item matches', HtmlAssertions::text($html, '//h2[@id="c-res"]'));

        $html = $this->editorPost(['rules' => [1 => ['field' => $this->f['Name'], 'test' => 'contains', 'value' => 'nothing like this']]]);
        self::assertSame('0 items match', HtmlAssertions::text($html, '//h2[@id="c-res"]'));
        self::assertSame('Nothing matches these rules yet.', HtmlAssertions::text($html, '//section[@class="collection-preview"]/p[@class="static-value"]'));
        self::assertSame(0, $this->xpath($html)->query('//section[@class="collection-preview"]//table')->length);

        for ($i = 1; $i <= 4; $i++) {
            $this->add(['Name' => 'Example item ' . $i, 'Under warranty' => '1']);
        }
        $html = $this->editorPost(['rules' => [1 => ['field' => $this->f['Under warranty'], 'test' => 'ticked']]]);
        self::assertSame('6 items match (first 5 shown)', HtmlAssertions::text($html, '//h2[@id="c-res"]'));
        self::assertSame('(first 5 shown)', HtmlAssertions::text($html, '//h2[@id="c-res"]/span[@class="preview-count-note"]'));
        self::assertSame(5, $this->xpath($html)->query('//section[@class="collection-preview"]//tbody/tr')->length);
    }

    public function testEnterInATextBoxPreviews(): void
    {
        // Without a submitter the browser sends the first submit button,
        // the hidden Preview; an unknown or missing action also previews.
        foreach (['preview', 'unknown', '', 'remove-11', 'remove-0'] as $action) {
            $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => $action, 'name' => 'Draft', 'rules' => [1 => ['field' => $this->f['Name'], 'test' => 'contains', 'value' => 'oven']]]);
            self::assertSame(200, $response->status, $action);
            self::assertSame('1 item matches', HtmlAssertions::text($response->body, '//h2[@id="c-res"]'), $action);
            self::assertSame(1, $this->xpath($response->body)->query('//fieldset[contains(@class, "rule-row")]')->length, $action);
        }
        self::assertSame([], $this->ops->collections()->summaries($this->admin(), $this->project), 'Nothing is stored.');
    }

    public function testSaveCreatesAndAnswers303ToTheView(): void
    {
        $response = $this->post(self::ADMIN, $this->t('/collections'), [
            'action' => 'save',
            'name' => '  Under warranty ',
            'match' => 'all',
            'sort' => $this->f['Purchased'] . '.desc',
            'rules' => [
                1 => ['field' => $this->f['Under warranty'], 'test' => 'ticked', 'value' => ''],
                2 => ['field' => $this->f['Purchased'], 'test' => 'after', 'value' => '2023-01-01'],
            ],
        ]);
        $summaries = $this->ops->collections()->summaries($this->admin(), $this->project);
        self::assertCount(1, $summaries);
        self::assertSame(303, $response->status);
        self::assertSame($this->p('/collections/' . $summaries[0]->key), $response->header('Location'));
        self::assertSame(['key' => 'notice.collection_saved', 'params' => []], $this->services->flash[self::ADMIN]);
        self::assertSame('Under warranty', $summaries[0]->name);
        self::assertSame(2, $summaries[0]->count);

        $this->services->flash[self::ADMIN] = ['key' => 'notice.collection_saved', 'params' => []];
        $html = $this->page(self::ADMIN, $this->p('/collections/' . $summaries[0]->key));
        self::assertSame('The collection has been saved.', HtmlAssertions::text($html, '//main//div[contains(@class, "notice")]'));
    }

    public function testUpdateSavesOverTheCollection(): void
    {
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]);
        $response = $this->post(self::ADMIN, $this->c($key, '/edit'), ['action' => 'save', 'name' => 'Cookware', 'match' => 'any', 'rules' => [1 => ['field' => $this->f['Category'], 'test' => 'is', 'value' => $this->choice['Cookware']]]]);
        self::assertSame(303, $response->status);
        self::assertSame($this->c($key), $response->header('Location'));
        $summaries = $this->ops->collections()->summaries($this->admin(), $this->project);
        self::assertSame([['Cookware', 1]], [[$summaries[0]->name, $summaries[0]->count]]);

        // Preview on an existing collection stores nothing.
        $response = $this->post(self::ADMIN, $this->c($key, '/edit'), ['action' => 'preview', 'name' => 'Changed', 'rules' => []]);
        self::assertSame(200, $response->status);
        self::assertSame(['Changed'], $this->values($this->xpath($response->body), '//input[@id="f-name"]'));
        self::assertSame('Cookware', $this->ops->collections()->summaries($this->admin(), $this->project)[0]->name);
    }

    public function testProblemsGive422WithEveryProblemLinkedFromTheSummary(): void
    {
        $this->save('Taken', []);
        $response = $this->post(self::ADMIN, $this->t('/collections'), [
            'action' => 'save',
            'name' => 'TAKEN',
            'rules' => [
                1 => ['field' => $this->f['Purchased'], 'test' => 'on', 'value' => 'not a date'],
                2 => ['field' => '', 'test' => ''],
                3 => ['field' => $this->f['Name'], 'test' => 'contains', 'value' => ''],
                4 => ['field' => $this->f['Under warranty'], 'test' => 'ticked'],
            ],
        ]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        $x = $this->xpath($response->body);
        self::assertStringStartsWith('Error: ', HtmlAssertions::text($response->body, '//title'));

        $summary = [];
        foreach ($x->query('//div[@class="error-summary"]//li/a') as $a) {
            \assert($a instanceof \DOMElement);
            $summary[] = [$a->getAttribute('href'), trim($a->textContent)];
        }
        self::assertSame([
            ['#f-name', 'Another collection already has this name.'],
            ['#f-rule-1-field', 'Enter a date.'],
            ['#f-rule-2-field', 'Choose a field for this rule.'],
            ['#f-rule-3-field', 'Enter a value for this rule.'],
        ], $summary);
        foreach ($summary as [$href]) {
            self::assertSame(1, $x->query('//*[@id="' . substr($href, 1) . '"]')->length, $href);
        }
        self::assertSame(['true', 'f-name-error'], [$this->attr($x, '//input[@id="f-name"]', 'aria-invalid'), $this->attr($x, '//input[@id="f-name"]', 'aria-describedby')]);
        self::assertSame('Another collection already has this name.', HtmlAssertions::text($response->body, '//p[@id="f-name-error"]'));
        self::assertSame(['TAKEN'], $this->values($x, '//input[@id="f-name"]'));
        self::assertSame(['not a date'], $this->values($x, '//input[@name="rules[1][value]"]'));
        self::assertSame('Enter a date.', HtmlAssertions::text($response->body, '//p[@id="f-rule-1-problem"][@class="error-text rule-problem"]'));
        self::assertSame(['true', 'f-rule-1-problem'], [$this->attr($x, '//select[@id="f-rule-1-field"]', 'aria-invalid'), $this->attr($x, '//select[@id="f-rule-1-field"]', 'aria-describedby')]);
        self::assertSame(0, $x->query('//select[@id="f-rule-4-field"][@aria-invalid]')->length);
        self::assertSame('Fix the rules above to see what matches.', HtmlAssertions::text($response->body, '//section[@class="collection-preview"]/p'));
        self::assertCount(1, $this->ops->collections()->summaries($this->admin(), $this->project));

        // An empty name: the summary links to the name box.
        $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => 'save', 'name' => '', 'rules' => []]);
        self::assertSame(422, $response->status);
        self::assertSame(['#f-name'], $this->values($this->xpath($response->body), '//div[@class="error-summary"]//a/@href'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function wrongShapes(): iterable
    {
        yield 'name as an array' => [['name' => ['x'], 'rules' => []]];
        yield 'match as an array' => [['name' => 'Ok', 'match' => ['any'], 'rules' => [1 => ['field' => ['x']]]]];
        yield 'rules as text' => [['name' => ['Ok'], 'rules' => 'x']];
        yield 'a row as text' => [['name' => 'Ok', 'rules' => [1 => 'x']]];
        yield 'a field as an array' => [['name' => 'Ok', 'rules' => [1 => ['field' => ['a' => 'b'], 'test' => ['c'], 'value' => ['d'], 'record' => [[]]]]]];
        yield 'nested rows' => [['name' => 'Ok', 'rules' => [1 => [1 => ['field' => 'x']]]]];
        yield 'sort as an array' => [['name' => ['Ok'], 'sort' => ['x'], 'rules' => []]];
        yield 'keys that are not numbers' => [['name' => 'Ok', 'rules' => ['a' => ['field' => 'zzzzzzzzzzzz', 'test' => 'is', 'value' => '1'], 'b' => 'x']]];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('wrongShapes')]
    public function testPostedArraysOfTheWrongShapeGive422AndNever500(array $post): void
    {
        $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => 'save'] + $post);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertSame([], $this->ops->collections()->summaries($this->admin(), $this->project));

        foreach (['preview', 'add', 'remove-1', ['x']] as $action) {
            $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => $action] + $post);
            self::assertSame(200, $response->status);
            $this->assertScreen($response->body);
        }
    }

    public function testExistingEditor(): void
    {
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked'), $this->rule('Purchased', 'after', ['2023-01-01']), $this->rule('Room', 'links-to', [(string) $this->r['Kitchen']]), $this->rule('Category', 'is', [$this->choice['Appliance']])], 'any', $this->f['Purchased'] . '.desc');
        $html = $this->page(self::MEMBER, $this->c($key, '/edit'), true);
        $x = $this->xpath($html);

        self::assertSame('Under warranty - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(['Home Inventory', 'Items', 'Collection: Under warranty'], $this->crumbs($x));
        self::assertSame('Under warranty', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame($this->c($key, '/edit'), $this->attr($x, '//form[@id="collection-form"]', 'action'));
        self::assertSame(['Under warranty'], $this->values($x, '//input[@id="f-name"]'));
        self::assertSame(['any'], $this->values($x, '//input[@name="match"][@checked]'));
        self::assertSame([$this->f['Under warranty'], $this->f['Purchased'], $this->f['Room'], $this->f['Category']], $this->values($x, '//select[@data-rule-field]/option[@selected]'));
        self::assertSame(['ticked', 'after', 'links-to', 'is'], $this->values($x, '//select[contains(@name, "[test]")]/option[@selected]'));
        self::assertSame(['2023-01-01'], $this->values($x, '//input[@name="rules[2][value]"]'));
        self::assertSame([(string) $this->r['Kitchen']], $this->values($x, '//select[@name="rules[3][record]"]/option[@selected]'));
        self::assertSame([$this->choice['Appliance']], $this->values($x, '//select[@name="rules[4][value]"]/option[@selected]'));
        self::assertSame([$this->f['Purchased'] . '.desc'], $this->values($x, '//select[@id="f-sort"]/option[@selected]'));
        self::assertSame($this->c($key), $this->attr($x, '//div[@class="collection-actions"]/a', 'href'), 'Cancel goes to the collection.');
        self::assertSame($this->c($key, '/delete'), $this->attr($x, '//main/p/a[normalize-space(.)="Delete this collection"]', 'href'));
        self::assertSame(0, $x->query('//fieldset[contains(@class, "rule-broken")]')->length);
        // Preview rows carry the collection state (P162).
        self::assertSame($this->t('/records/' . $this->r['Stand mixer']) . '?collection=' . $key, $this->attr($x, '//section[@class="collection-preview"]//a[normalize-space(.)="Stand mixer"]', 'href'));
        $this->assertOrder($x, ['//div[@class="collection-actions"]', '//main/p/a[normalize-space(.)="Delete this collection"]', '//section[@class="collection-preview"]']);
    }

    public function testBrokenRulesAreShownAndIgnored(): void
    {
        $key = $this->save('Kitchen things', [$this->rule('Room', 'links-to', [(string) $this->r['Kitchen']]), $this->rule('Under warranty', 'ticked')]);
        $two = $this->save('Two broken', [$this->rule('Room', 'links-to', [(string) $this->r['Kitchen']]), $this->rule('Room', 'links-to', [(string) $this->r['Kitchen']])]);
        $this->ops->records()->trash($this->admin(), $this->project, $this->rooms, (string) $this->r['Kitchen']);

        $html = $this->page(self::ADMIN, $this->c($key, '/edit'));
        $x = $this->xpath($html);
        self::assertSame(1, $x->query('//fieldset[@class="rule-row rule-broken"]')->length);
        self::assertSame('Rule 1', HtmlAssertions::text($html, '//fieldset[@class="rule-row rule-broken"]/legend'));
        self::assertSame(
            'This rule no longer works and is ignored: The record this rule used is in the trash or has been deleted.',
            HtmlAssertions::text($html, '//fieldset[@class="rule-row rule-broken"]/p[@class="rule-problem"][@id="f-rule-1-problem"]'),
        );
        self::assertSame('f-rule-1-problem', $this->attr($x, '//select[@id="f-rule-1-field"]', 'aria-describedby'));
        self::assertSame(0, $x->query('//select[@id="f-rule-1-field"][@aria-invalid]')->length, 'Broken is not invalid.');
        self::assertSame('2 items match', HtmlAssertions::text($html, '//h2[@id="c-res"]'), 'The broken rule is ignored.');

        $html = $this->page(self::ADMIN, $this->c($key));
        $x = $this->xpath($html);
        self::assertSame('status', $this->attr($x, '//div[@class="notice notice-warn"]', 'role'));
        self::assertSame('1 rule of this collection no longer works and is ignored. Edit the collection to fix or remove it.', HtmlAssertions::text($html, '//div[@class="notice notice-warn"]/p'));
        self::assertSame($this->c($key, '/edit'), $this->attr($x, '//div[@class="notice notice-warn"]/a', 'href'));
        self::assertSame(['Cordless drill', 'Stand mixer'], $this->texts($x, '//tbody/tr/th/a'));

        // Two broken rules: plural.
        $html = $this->page(self::ADMIN, $this->c($two));
        self::assertSame('2 rules of this collection no longer work and are ignored. Edit the collection to fix or remove them.', HtmlAssertions::text($html, '//div[@class="notice notice-warn"]/p'));
    }

    // ------------------------------------------------------------------ view (section 2)

    public function testCollectionView(): void
    {
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')], 'all', $this->f['Purchased'] . '.desc');
        $other = $this->save('Cookware', [$this->rule('Category', 'is', [$this->choice['Cookware']])]);
        $html = $this->page(self::MEMBER, $this->c($key), true);
        $x = $this->xpath($html);

        self::assertSame('Under warranty, Home Inventory - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame(1, $x->query('//div[@class="app-columns"]/nav[@class="sidebar"]/following-sibling::main[@id="main"][@class="page page-list"]')->length);
        self::assertSame(['Under warranty 2'], $this->texts($x, '//nav[@class="sidebar"]//a[@aria-current="page"]'), 'The collection is current; no table is.');
        self::assertSame(['Under warranty 2', 'Cookware 1', 'New collection'], $this->texts($x, '//nav[@class="sidebar"]/div[@class="sidebar-group"][h2[normalize-space(.)="Collections"]]/a'));
        self::assertSame($this->c($other), $this->attr($x, '//nav[@class="sidebar"]//a[normalize-space(.)="Cookware 1"]', 'href'));
        self::assertSame($this->t('/collections/new'), $this->attr($x, '//nav[@class="sidebar"]//a[@class="sidebar-link sidebar-add"]', 'href'));

        self::assertSame('Under warranty', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('2 records in Items match. Sorted by Purchased, newest first.', HtmlAssertions::text($html, '//div[@class="page-head"]//p[@class="lead"]'));
        self::assertSame(['Edit collection', 'Add item'], $this->texts($x, '//div[@class="page-actions"]/a'));
        self::assertSame($this->c($key, '/edit'), $this->attr($x, '//div[@class="page-actions"]/a[1]', 'href'));
        self::assertSame(0, $x->query('//div[contains(@class, "notice-warn")]')->length);

        // Controls: find and sort, no Filter.
        self::assertSame($this->c($key), $this->attr($x, '//form[@class="list-controls"]', 'action'));
        self::assertSame(0, $x->query('//form[@class="list-controls"]/a | //form[@class="list-controls"]/input[@type="hidden"]')->length);
        self::assertSame([$this->f['Purchased'] . '.desc'], $this->values($x, '//select[@id="sort"]/option[@selected]'), "The collection's sort is the default.");

        self::assertSame(['Stand mixer', 'Cordless drill'], $this->texts($x, '//tbody/tr/th/a'));
        self::assertSame($this->t('/records/' . $this->r['Stand mixer']) . '?collection=' . $key, $this->attr($x, '//tbody/tr[1]/th/a', 'href'), 'Record links carry the collection.');

        // Find and sort are kept.
        $html = $this->page(self::MEMBER, $this->c($key, '?q=drill&sort=' . $this->f['Name'] . '.asc'));
        $x = $this->xpath($html);
        self::assertSame(['drill'], $this->values($x, '//input[@id="find"]'));
        self::assertSame([$this->f['Name'] . '.asc'], $this->values($x, '//select[@id="sort"]/option[@selected]'));
        self::assertSame(['Cordless drill'], $this->texts($x, '//tbody/tr/th/a'));
        self::assertSame(
            $this->t('/records/' . $this->r['Cordless drill']) . '?q=drill&sort=' . $this->f['Name'] . '.asc&collection=' . $key,
            $this->attr($x, '//tbody/tr[1]/th/a', 'href'),
        );
        // The request's filter is ignored.
        $html = $this->page(self::MEMBER, $this->c($key, '?filter=' . $this->f['Category'] . '&op=is&v=Tool'));
        self::assertSame(['Stand mixer', 'Cordless drill'], $this->texts($this->xpath($html), '//tbody/tr/th/a'));
        self::assertSame(0, $this->xpath($html)->query('//div[contains(@class, "filter-bar")]')->length);
    }

    public function testCollectionViewPagesWithoutRepeatingTheKey(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->add(['Name' => sprintf('Example item %02d', $i), 'Under warranty' => '1']);
        }
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]);
        $html = $this->page(self::ADMIN, $this->c($key, '?q=Example'));
        $hrefs = $this->values($this->xpath($html), '//nav[@class="pager"]//a/@href');
        self::assertNotSame([], $hrefs);
        foreach ($hrefs as $href) {
            self::assertStringStartsWith($this->c($key) . '?', $href);
            self::assertStringNotContainsString('collection=', $href);
            self::assertStringContainsString('q=Example', $href);
        }
    }

    public function testEmptyCollectionView(): void
    {
        $key = $this->save('Nothing yet', [$this->rule('Name', 'contains', ['no such thing'])]);
        $html = $this->page(self::ADMIN, $this->c($key));
        $x = $this->xpath($html);
        self::assertSame(['Nothing matches', 'No items match this collection yet. It updates by itself as records change.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame($this->c($key, '/edit'), $this->attr($x, '//div[@class="empty-state"]/a', 'href'));
        self::assertSame('Edit collection', HtmlAssertions::text($html, '//div[@class="empty-state"]/a'));
        self::assertSame('0 records in Items match. Sorted by Name, A to Z.', HtmlAssertions::text($html, '//p[@class="lead"]'));
        self::assertSame(1, $x->query('//form[@class="list-controls"]')->length);

        // A search that finds nothing in the collection offers to clear it.
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]);
        $html = $this->page(self::ADMIN, $this->c($key, '?q=zzz'));
        $x = $this->xpath($html);
        self::assertSame(['Nothing matches', 'No records match “zzz”.'], $this->texts($x, '//div[@class="empty-state"]/p'));
        self::assertSame($this->c($key), $this->attr($x, '//div[@class="empty-state"]/a', 'href'));
        self::assertSame('0 records in Items match. Sorted by Name, A to Z.', HtmlAssertions::text($html, '//p[@class="lead"]'));
    }

    // ------------------------------------------------------------------ delete (section 5)

    public function testDeleteConfirmationAndDelete(): void
    {
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]);
        $html = $this->page(self::MEMBER, $this->c($key, '/delete'), true);
        $x = $this->xpath($html);
        self::assertSame('Delete Under warranty? - TiffinDB', HtmlAssertions::text($html, '//title'));
        self::assertSame('Delete Under warranty?', HtmlAssertions::text($html, '//main//h1'));
        self::assertSame('The collection is deleted. Its items are not changed or moved to the trash. This cannot be undone.', HtmlAssertions::text($html, '//main/h1/following-sibling::p'));
        self::assertSame(['Home Inventory', 'Items', 'Collection: Under warranty', 'Delete'], $this->crumbs($x));
        self::assertSame(['post', $this->c($key, '/delete')], [$this->attr($x, '//main//form', 'method'), $this->attr($x, '//main//form', 'action')]);
        self::assertSame('Delete the collection', HtmlAssertions::text($html, '//main//form//button[@class="button button-large button-danger"]'));
        self::assertSame($this->c($key, '/edit'), $this->attr($x, '//div[@class="form-actions"]/a', 'href'));

        $response = $this->post(self::MEMBER, $this->c($key, '/delete'), []);
        self::assertSame(303, $response->status);
        self::assertSame($this->t(''), $response->header('Location'));
        self::assertSame(['key' => 'notice.collection_deleted', 'params' => ['name' => 'Under warranty']], $this->services->flash[self::MEMBER]);
        self::assertSame([], $this->ops->collections()->summaries($this->admin(), $this->project));
        self::assertSame(3, $this->ops->records()->listing($this->admin(), $this->project, $this->items, \TiffinDB\Core\App\ListRequest::none())->total, 'Records untouched.');
        self::assertSame(404, $this->request(self::MEMBER, 'GET', $this->c($key))->status);
        self::assertSame(404, $this->post(self::MEMBER, $this->c($key, '/delete'), [])->status);
    }

    // ------------------------------------------------------------------ escaping and access

    public function testHostileNamesAndValuesAreEscaped(): void
    {
        $evil = '<b>x</b>&"\'{n}';
        $key = $this->save($evil, [$this->rule('Name', 'contains', ['<script>alert(1)</script>'])]);
        $this->add(['Name' => 'A <script>alert(1)</script> item']);
        $this->ops->schema()->updateTable($this->admin(), $this->project, $this->items, '<i>Items</i>', 'it<em>m', $this->f['Name']);

        foreach ([$this->c($key), $this->c($key, '/edit'), $this->c($key, '/delete'), $this->t('')] as $uri) {
            $html = $this->page(self::ADMIN, $uri);
            self::assertStringNotContainsString('<script>alert', $html, $uri);
            self::assertStringNotContainsString('<b>x', $html, $uri);
            self::assertStringNotContainsString('<i>Items', $html, $uri);
            self::assertStringNotContainsString('<em>', $html, $uri);
            self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;&amp;&quot;', $html, $uri);
        }
        $html = $this->page(self::ADMIN, $this->c($key, '/edit'));
        $x = $this->xpath($html);
        self::assertSame([$evil], $this->values($x, '//input[@id="f-name"]'));
        self::assertSame(['<script>alert(1)</script>'], $this->values($x, '//input[@name="rules[1][value]"]'));
        self::assertSame('Collection: ' . $evil, $this->crumbs($x)[2]);
        self::assertSame('Show <i>items</i> that match', HtmlAssertions::text($html, '//legend[@class="legend-plain"]'));
        self::assertSame('A <script>alert(1)</script> item', HtmlAssertions::text($html, '//section[@class="collection-preview"]//tbody/tr/th/a'));

        // Posted hostile values are redisplayed escaped.
        $response = $this->post(self::ADMIN, $this->t('/collections'), ['action' => 'save', 'name' => str_repeat('<x>', 30), 'rules' => [1 => ['field' => $this->f['Value'], 'test' => 'is', 'value' => '"><script>alert(2)</script>']]]);
        self::assertSame(422, $response->status);
        $this->assertScreen($response->body);
        self::assertStringNotContainsString('<script>alert(2)', $response->body);
        self::assertSame(['"><script>alert(2)</script>'], $this->values($this->xpath($response->body), '//input[@name="rules[1][value]"]'));
    }

    public function testAccess(): void
    {
        $key = $this->save('Under warranty', [$this->rule('Under warranty', 'ticked')]);
        $routes = [
            ['GET', $this->c($key)],
            ['GET', $this->c($key, '/edit')],
            ['POST', $this->c($key, '/edit')],
            ['GET', $this->c($key, '/delete')],
            ['POST', $this->c($key, '/delete')],
            ['GET', $this->t('/collections/new')],
            ['POST', $this->t('/collections')],
        ];
        foreach ($routes as [$method, $uri]) {
            $response = $method === 'GET' ? $this->request(self::MEMBER, 'GET', $uri) : $this->post(self::MEMBER, $uri, ['action' => 'save', 'name' => 'Mine']);
            self::assertSame(404, $response->status, "Ungranted: {$method} {$uri}");
        }
        // Unknown keys and a collection from another table's address.
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->p('/collections/aaaaaaaaaaaa'))->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->p('/collections/aaaaaaaaaaaa/edit'))->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->p('/collections/aaaaaaaaaaaa/delete'))->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', $this->p('/tables/aaaaaaaaaaaa/collections/new'))->status);
        self::assertSame(404, $this->request(self::ADMIN, 'GET', '/projects/aaaaaaaaaaaa/collections/' . $key)->status);
        self::assertSame(404, $this->post(self::ADMIN, $this->p('/tables/aaaaaaaaaaaa/collections'), ['action' => 'preview'])->status);
        self::assertSame(403, Fixtures::handle($this->kernel, Fixtures::fullRequest('POST', $this->c($key, '/delete'), [], ['tdb_session' => self::ADMIN]))[0]->status, 'No CSRF token.');
        self::assertCount(1, $this->ops->collections()->summaries($this->admin(), $this->project));

        $this->share();
        foreach ([$this->c($key), $this->c($key, '/edit'), $this->c($key, '/delete'), $this->t('/collections/new')] as $uri) {
            self::assertSame(200, $this->request(self::MEMBER, 'GET', $uri)->status, "Granted: {$uri}");
        }
    }

    // ------------------------------------------------------------------ helpers

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

    private function share(): void
    {
        $this->ops->projects()->setSharing($this->admin(), $this->project, [$this->core->person('member')->key]);
    }

    /** @param array<string, mixed> $options */
    private function addField(string $name, string $type, array $options = []): void
    {
        $this->f[$name] = $this->ops->schema()->addField($this->admin(), $this->project, $this->items, $name, $type, $options, false, '')->key;
    }

    /** @param array<string, string> $text field name => form text */
    private function add(array $text): int
    {
        $input = [];
        foreach ($text as $name => $value) {
            $input[$this->f[$name]] = $value;
        }

        return $this->core->addRecord($this->project, $this->items, $input);
    }

    /**
     * A posted rule row as the editor sends it.
     *
     * @param list<string> $values
     * @return array<string, string>
     */
    private function rule(string $field, string $test, array $values = []): array
    {
        $row = ['field' => $this->f[$field], 'test' => $test, 'value' => '', 'record' => ''];
        if ($values !== []) {
            $row[$field === 'Room' ? 'record' : 'value'] = $values[0];
        }

        return $row;
    }

    /**
     * Saves a collection of Items through the core; returns its key.
     *
     * @param list<array<string, string>> $rules
     */
    private function save(string $name, array $rules, string $match = 'all', string $sort = ''): string
    {
        $posted = [];
        foreach ($rules as $i => $rule) {
            $posted[$i + 1] = $rule;
        }

        return $this->ops->collections()->save($this->admin(), $this->project, $this->items, null, ['name' => $name, 'match' => $match, 'sort' => $sort, 'rules' => $posted]);
    }

    /** @param array<string, mixed> $post */
    private function editorPost(array $post): string
    {
        $response = $this->post(self::ADMIN, $this->t('/collections'), $post + ['action' => 'preview', 'name' => '']);
        self::assertSame(200, $response->status);
        $this->assertScreen($response->body);

        return $response->body;
    }

    private function p(string $path): string
    {
        return '/projects/' . $this->project . $path;
    }

    private function t(string $path): string
    {
        return $this->p('/tables/' . $this->items . $path);
    }

    private function c(string $key, string $path = ''): string
    {
        return $this->p('/collections/' . $key . $path);
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

    private function page(string $token, string $uri, bool $share = false): string
    {
        if ($share) {
            $this->share();
        }
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

        return $node instanceof \DOMElement && $node->hasAttribute($name) ? $node->getAttribute($name) : null;
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
            $values[] = $node instanceof \DOMElement ? $node->getAttribute('value') : (string) $node->nodeValue;
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

    /** @param list<string> $queries elements expected in this document order */
    private function assertOrder(DOMXPath $x, array $queries): void
    {
        $positions = [];
        $all = iterator_to_array($x->query('//*'));
        foreach ($queries as $query) {
            $node = $x->query($query)->item(0);
            self::assertNotNull($node, $query);
            $positions[] = array_search($node, $all, true);
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Reading order.');
    }
}
