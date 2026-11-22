<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Tests\Support\HtmlAssertions;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Web\Appearance;
use TiffinDB\Web\Format;
use TiffinDB\Web\Ids;
use TiffinDB\Web\Request;
use TiffinDB\Web\UploadedFile;
use TiffinDB\Web\Strings;
use TiffinDB\Web\UrlGenerator;
use TiffinDB\Web\ValueText;
use TiffinDB\Web\View;
use TiffinDB\Web\ViewScope;

/**
 * T-WEB-27: the shared M2 partials (M2 spec part 2 section 4.1) rendered
 * through View with the real templates and strings, by the fixture page
 * tests/web/fixtures/templates/pages/m2-components.php copied next to
 * them: field-input for all 14 editable types, the value() helper in both
 * modes for every type with a column, record-table cells, the pager, the
 * empty state, and stored-form inline content. Every page is well-formed,
 * has no inline code, and passes assertForm; values holding markup,
 * quotes, ampersands, and `{name}` are escaped once and never treated as
 * placeholders. Synthetic data only.
 *
 * T-WEB-42 (M3 spec part 2 section 5.1): the shared M3 partials (steps,
 * file-field, facts, warning-panel, progress, continue-form) through the
 * fixture page m3-components.php: structure, ids, aria-current on the
 * current step, progress without a total, the auto-continue marker, and
 * hostile plain-text values escaped once.
 *
 * T-WEB-56 (M4 spec part 2 section 6.1): the shared M4 partials
 * (link-control, link-rows, file-rows, leave-note) through the fixture
 * page m4-components.php: structure and classes of
 * docs/design/m4/components.md section 3, accessible names that start
 * with the visible text and name the record or file, `form` attributes
 * that name the unlink form, images only when given and only with a url()
 * src, the new-record notes, and hostile titles, names, and paths
 * escaped once.
 */
#[CoversClass(ValueText::class)]
#[CoversClass(ViewScope::class)]
final class ComponentsTest extends TestCase
{
    private const TOKEN = 'csrf-token_0123456789abcdef';

    private const VERSION = '9.9.9-test';

    private const TABLE = 'mnopqrstuvwx';

    /** A value with every character that must be escaped, and a placeholder. */
    private const HOSTILE = '<script>alert("x")</script> & \'q\' {name}';

    private static string $templates = '';

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir() . '/tiffindb-components-test-' . bin2hex(random_bytes(6));
        self::copyTree(RepoFiles::path('templates'), $dir);
        foreach (['m2-components', 'm3-components', 'm4-components'] as $fixture) {
            if (!copy(__DIR__ . "/fixtures/templates/pages/{$fixture}.php", $dir . "/pages/{$fixture}.php")) {
                throw new RuntimeException('Cannot copy the fixture.');
            }
        }
        self::$templates = $dir;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$templates !== '' && is_dir(self::$templates)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$templates, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir(self::$templates);
        }
        self::$templates = '';
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0700, true) && !is_dir($to)) {
            throw new RuntimeException('Cannot create a temporary directory.');
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                if (!mkdir($target, 0700) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create a temporary directory.');
                }
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Cannot copy a template.');
            }
        }
    }

    // Helpers.

    private static function strings(): Strings
    {
        return new Strings(RepoFiles::path('strings/en.php'));
    }

    private static function format(): Format
    {
        return new Format(
            self::strings(),
            new DateTimeZone('UTC'),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-12T10:00:00Z'),
        );
    }

    private static function valueText(): ValueText
    {
        return new ValueText(self::strings(), self::format());
    }

    /** @param array<string, string|int> $params */
    private static function text(string $key, array $params = []): string
    {
        return self::strings()->get($key, $params);
    }

    private static function sourceUrl(): string
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        self::assertIsString($config['source_url']);

        return $config['source_url'];
    }

    /** @param array<string, mixed> $data */
    private static function render(array $data, string $basePath = ''): string
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        $view = (new View(
            self::$templates,
            self::strings(),
            new UrlGenerator($basePath, self::VERSION),
            Appearance::default(),
            $config,
        ))->withCsrfToken(self::TOKEN)->withFormat(self::format());
        $html = $view->page('m2-components', ['title' => 'Components', ...$data])->body;

        HtmlAssertions::assertWellFormed($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
        HtmlAssertions::assertForm($html);
        self::assertStringNotContainsString('<script>alert', $html, 'Markup in values is escaped.');
        self::assertStringNotContainsString('&amp;lt;', $html, 'Nothing is escaped twice.');
        self::assertStringNotContainsString('&amp;amp;', $html, 'Nothing is escaped twice.');

        return $html;
    }

    private static function xpath(string $html): DOMXPath
    {
        return new DOMXPath(HtmlAssertions::assertWellFormed($html));
    }

    private static function one(DOMXPath $xpath, string $query): DOMElement
    {
        $nodes = $xpath->query($query);
        self::assertSame(1, $nodes->length, "Exactly one match for {$query}.");
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private static function norm(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<string, string> */
    private static function attributes(DOMElement $element): array
    {
        $map = [];
        foreach ($element->attributes as $attribute) {
            /** @var \DOMAttr $attribute */
            $map[$attribute->nodeName] = (string) $attribute->nodeValue;
        }
        ksort($map);

        return $map;
    }

    /** @param array<string, mixed> $options */
    private static function field(string $type, array $options = [], bool $required = false, string $key = 'abcdefghijkl', string $name = 'Name'): FieldDef
    {
        $hasColumn = !in_array($type, ['relation', 'attachment'], true);

        return new FieldDef($key, self::TABLE, $name, $type, $options, $required, '', 1, $hasColumn);
    }

    /**
     * One record form slot for the fixture.
     *
     * @return array{input: array<string, mixed>, help: string|null, error: string|null}
     */
    private static function slot(
        FieldDef $field,
        string $text = '',
        bool $submitted = false,
        ?string $help = null,
        ?string $error = null,
        ?string $currencyName = null,
    ): array {
        $describedBy = [];
        if ($help !== null) {
            $describedBy[] = 'help';
        }
        if ($error !== null) {
            $describedBy[] = 'err';
        }

        return [
            'input' => [
                'field' => $field,
                'key' => $field->key,
                'text' => $text,
                'submitted' => $submitted,
                'required' => $field->required,
                'invalid' => $error !== null,
                'describedBy' => $describedBy,
                'label' => $field->name,
                'currencyName' => $currencyName,
            ],
            'help' => $help,
            'error' => $error,
        ];
    }

    // field-input.

    /**
     * Each editable type: options, bound text, expected element and
     * attributes of the control (besides class, id, name).
     *
     * @return iterable<string, array{string, array<string, mixed>, string, string, array<string, string>}>
     */
    public static function editableTypes(): iterable
    {
        yield 'text' => ['text', ['max_length' => 120], 'Stand mixer', 'input', ['type' => 'text', 'maxlength' => '120', 'value' => 'Stand mixer']];
        yield 'long text' => ['long_text', ['max_length' => 5000], '', 'textarea', ['rows' => '4', 'maxlength' => '5000']];
        yield 'whole number' => ['number', ['decimals' => 0], '1234', 'input', ['type' => 'text', 'inputmode' => 'numeric', 'value' => '1234']];
        yield 'decimal number' => ['number', ['decimals' => 2], '1234.50', 'input', ['type' => 'text', 'inputmode' => 'decimal', 'value' => '1234.50']];
        yield 'currency' => ['currency', ['code' => 'USD', 'minor_digits' => 2], '329.00', 'input', ['type' => 'text', 'inputmode' => 'decimal', 'value' => '329.00']];
        yield 'date' => ['date', [], '2025-03-14', 'input', ['type' => 'date', 'value' => '2025-03-14']];
        yield 'time' => ['time', ['seconds' => false], '14:30:00', 'input', ['type' => 'time', 'step' => '60', 'value' => '14:30']];
        yield 'time with seconds' => ['time', ['seconds' => true], '14:30:15', 'input', ['type' => 'time', 'step' => '1', 'value' => '14:30:15']];
        yield 'date and time' => ['date_time', ['seconds' => false], '2025-03-14T14:30:00', 'input', ['type' => 'datetime-local', 'step' => '60', 'value' => '2025-03-14T14:30']];
        yield 'date and time with seconds' => ['date_time', ['seconds' => true], '2025-03-14T14:30:15', 'input', ['type' => 'datetime-local', 'step' => '1', 'value' => '2025-03-14T14:30:15']];
        yield 'duration' => ['duration', ['seconds' => false], '1:30:00', 'input', ['type' => 'text', 'value' => '1:30']];
        yield 'duration with seconds' => ['duration', ['seconds' => true], '1:30:05', 'input', ['type' => 'text', 'value' => '1:30:05']];
        yield 'web address' => ['url', [], 'https://example.com/manual', 'input', ['type' => 'url', 'maxlength' => '2048', 'value' => 'https://example.com/manual']];
        yield 'email' => ['email', [], 'user@example.com', 'input', ['type' => 'email', 'maxlength' => '254', 'value' => 'user@example.com']];
        yield 'phone' => ['phone', [], '555-0100', 'input', ['type' => 'tel', 'maxlength' => '40', 'value' => '555-0100']];
    }

    /** @param array<string, mixed> $options
     *  @param array<string, string> $expected */
    #[DataProvider('editableTypes')]
    public function testFieldInputForEachTextLikeType(string $type, array $options, string $text, string $element, array $expected): void
    {
        $field = self::field($type, $options);
        $xpath = self::xpath(self::render(['fields' => [self::slot($field, $text)]]));

        $control = self::one($xpath, '//form//*[@id="f-abcdefghijkl"]');
        self::assertSame($element, $control->nodeName);
        ksort($expected);
        self::assertSame('input', $control->getAttribute('class'));
        self::assertSame('f_abcdefghijkl', $control->getAttribute('name'));
        self::assertSame($expected, array_diff_key(self::attributes($control), ['class' => 1, 'id' => 1, 'name' => 1]));
    }

    public function testTheFourteenEditableTypesAreCovered(): void
    {
        $types = array_unique(array_merge(array_column(iterator_to_array(self::editableTypes()), 0), ['checkbox', 'choice', 'rating']));
        sort($types);
        $editable = ValueText::EDITABLE;
        sort($editable);
        self::assertSame($editable, array_values($types));
        self::assertCount(14, $editable);
    }

    public function testLongTextKeepsLineBreaksAsText(): void
    {
        $xpath = self::xpath(self::render(['fields' => [self::slot(self::field('long_text'), "First line\nSecond & <b>third</b>")]]));

        self::assertSame("First line\nSecond & <b>third</b>", self::one($xpath, '//textarea')->textContent);
    }

    /** @return iterable<string, array{bool, string|null, string|null, array<string, string>}> */
    public static function describedCombinations(): iterable
    {
        yield 'nothing' => [false, null, null, []];
        yield 'required' => [true, null, null, ['required' => 'required']];
        yield 'help' => [false, 'Shown on the list.', null, ['aria-describedby' => 'help-abcdefghijkl']];
        yield 'error' => [false, null, 'Fill this in.', ['aria-describedby' => 'err-abcdefghijkl', 'aria-invalid' => 'true']];
        yield 'required, help, and error' => [true, 'Shown on the list.', 'Fill this in.', [
            'aria-describedby' => 'help-abcdefghijkl err-abcdefghijkl', 'aria-invalid' => 'true', 'required' => 'required',
        ]];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('describedCombinations')]
    public function testRequiredInvalidAndHelp(bool $required, ?string $help, ?string $error, array $expected): void
    {
        foreach (['text', 'long_text', 'choice', 'checkbox', 'date'] as $type) {
            $field = self::field($type, $type === 'choice' ? ['choices' => [['key' => 'a', 'label' => 'Tool']]] : [], $required);
            $xpath = self::xpath(self::render(['fields' => [self::slot($field, '', false, $help, $error)]]));
            $control = self::one($xpath, '//*[@id="f-abcdefghijkl"]');
            $actual = array_intersect_key(self::attributes($control), ['aria-describedby' => 1, 'aria-invalid' => 1, 'required' => 1]);
            self::assertSame($expected, $actual, $type);
        }
    }

    public function testRatingRadiosCarryTheSameAttributes(): void
    {
        $field = self::field('rating', ['max' => 5], true);
        $xpath = self::xpath(self::render(['fields' => [self::slot($field, '4', false, 'How good it is.', 'Choose a rating from 1 to 5.')]]));

        $radios = $xpath->query('//div[@class="choice-row"]/label[@class="check"]/input[@type="radio"]');
        self::assertSame(6, $radios->length);
        foreach ($radios as $radio) {
            /** @var DOMElement $radio */
            self::assertSame('f_abcdefghijkl', $radio->getAttribute('name'));
            self::assertSame('help-abcdefghijkl err-abcdefghijkl', $radio->getAttribute('aria-describedby'));
            self::assertSame('true', $radio->getAttribute('aria-invalid'));
            self::assertSame('required', $radio->getAttribute('required'));
        }
    }

    public function testTypedTextIsShownBackExactly(): void
    {
        $slots = [];
        $types = ['text', 'number', 'currency', 'date', 'time', 'date_time', 'duration', 'url', 'email', 'phone'];
        $keys = [];
        foreach ($types as $i => $type) {
            $key = 'typed' . str_repeat('x', 6) . chr(97 + $i);
            $keys[$type] = $key;
            $slots[] = self::slot(self::field($type, [], false, $key), self::HOSTILE, true, null, 'Not valid.');
        }
        $slots[] = self::slot(self::field('long_text', [], false, 'typedlongtxt'), self::HOSTILE, true);
        $slots[] = self::slot(self::field('time', [], false, 'typedtimesec'), '14:30:15', true);
        $html = self::render(['fields' => $slots]);
        $xpath = self::xpath($html);

        foreach ($keys as $type => $key) {
            self::assertSame(self::HOSTILE, self::one($xpath, '//*[@id="f-' . $key . '"]')->getAttribute('value'), $type);
        }
        self::assertSame(self::HOSTILE, self::one($xpath, '//*[@id="f-typedlongtxt"]')->textContent);
        self::assertSame('14:30:15', self::one($xpath, '//*[@id="f-typedtimesec"]')->getAttribute('value'), 'Typed seconds are kept.');
        self::assertStringContainsString('value="&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;q&apos; {name}"', $html);
    }

    public function testCheckbox(): void
    {
        $slots = [
            self::slot(self::field('checkbox', [], false, 'tickedboxaaa', 'Under warranty'), '1'),
            self::slot(self::field('checkbox', [], false, 'untickedboxa', 'Gift'), '0'),
            self::slot(self::field('checkbox', [], true, 'typedboxaaaa', self::HOSTILE), '1', true),
            self::slot(self::field('checkbox', [], false, 'emptyboxaaaa', 'Boxed'), ''),
        ];
        $xpath = self::xpath(self::render(['fields' => $slots]));

        $ticked = self::one($xpath, '//*[@id="f-tickedboxaaa"]');
        self::assertSame(['checked' => 'checked', 'id' => 'f-tickedboxaaa', 'name' => 'f_tickedboxaaa', 'type' => 'checkbox', 'value' => '1'], self::attributes($ticked));
        $label = $ticked->parentNode;
        self::assertInstanceOf(DOMElement::class, $label);
        self::assertSame(['label', 'check label'], [$label->nodeName, $label->getAttribute('class')]);
        self::assertSame('Under warranty', self::norm($label->textContent));
        self::assertFalse(self::one($xpath, '//*[@id="f-untickedboxa"]')->hasAttribute('checked'));
        self::assertFalse(self::one($xpath, '//*[@id="f-emptyboxaaaa"]')->hasAttribute('checked'));
        $typed = self::one($xpath, '//*[@id="f-typedboxaaaa"]');
        self::assertSame('checked', $typed->getAttribute('checked'));
        self::assertSame('required', $typed->getAttribute('required'));
        self::assertSame(self::HOSTILE . ' ' . self::text('input.required'), self::norm((string) $typed->parentNode?->textContent));
        self::assertSame(self::text('input.required'), self::norm(self::one($xpath, '//*[@id="f-typedboxaaaa"]/following-sibling::span/span[@class="label-optional"]')->textContent));
    }

    public function testChoice(): void
    {
        $choices = [['key' => 'a', 'label' => 'Appliance'], ['key' => 'b', 'label' => self::HOSTILE], ['key' => 'c', 'label' => 'Tool']];
        $slots = [
            self::slot(self::field('choice', ['choices' => $choices], false, 'choiceaaaaaa'), 'Tool'),
            self::slot(self::field('choice', ['choices' => $choices], false, 'choicebbbbbb'), self::HOSTILE),
            self::slot(self::field('choice', ['choices' => $choices], false, 'choicecccccc'), 'tool', true),
            self::slot(self::field('choice', ['choices' => $choices], false, 'choicedddddd'), ''),
        ];
        $xpath = self::xpath(self::render(['fields' => $slots]));

        $select = self::one($xpath, '//select[@id="f-choiceaaaaaa"]');
        self::assertSame(['class' => 'input', 'id' => 'f-choiceaaaaaa', 'name' => 'f_choiceaaaaaa'], self::attributes($select));
        $options = [];
        foreach ($xpath->query('./option', $select) as $option) {
            /** @var DOMElement $option */
            $options[] = [$option->getAttribute('value'), self::norm($option->textContent), $option->getAttribute('selected')];
        }
        self::assertSame(
            [['', self::text('input.choice.none'), ''], ['Appliance', 'Appliance', ''], [self::HOSTILE, self::HOSTILE, ''], ['Tool', 'Tool', 'selected']],
            $options,
        );
        self::assertSame(self::HOSTILE, self::one($xpath, '//select[@id="f-choicebbbbbb"]/option[@selected]')->getAttribute('value'));
        self::assertSame(0, $xpath->query('//select[@id="f-choicecccccc"]/option[@selected]')->length, 'Exact match on the label only.');
        self::assertSame(0, $xpath->query('//select[@id="f-choicedddddd"]/option[@selected]')->length);
    }

    public function testRating(): void
    {
        $slots = [
            self::slot(self::field('rating', ['max' => 5], false, 'ratingaaaaaa', 'Condition'), '4'),
            self::slot(self::field('rating', ['max' => 3], false, 'ratingbbbbbb', 'Mood'), ''),
        ];
        $xpath = self::xpath(self::render(['fields' => $slots]));

        $labels = [];
        foreach ($xpath->query('//fieldset[1]//div[@class="choice-row"]/label') as $label) {
            /** @var DOMElement $label */
            $input = $xpath->query('./input', $label)->item(0);
            self::assertInstanceOf(DOMElement::class, $input);
            $labels[] = [self::norm($label->textContent), $input->getAttribute('value'), $input->getAttribute('checked'), $input->getAttribute('id')];
        }
        self::assertSame(
            [
                [self::text('input.rating.low', ['n' => 1]), '1', '', 'f-ratingaaaaaa'],
                ['2', '2', '', ''],
                ['3', '3', '', ''],
                ['4', '4', 'checked', ''],
                [self::text('input.rating.high', ['n' => 5]), '5', '', ''],
                [self::text('input.rating.none'), '', '', ''],
            ],
            $labels,
        );
        self::assertSame('', self::one($xpath, '//fieldset[2]//input[@checked]')->getAttribute('value'), 'No value: "Not rated" is checked.');
        self::assertSame(4, $xpath->query('//fieldset[2]//input[@type="radio"]')->length);
    }

    public function testCurrencyLabel(): void
    {
        $text = self::valueText();
        $slots = [
            self::slot(self::field('currency', ['code' => 'USD', 'minor_digits' => 2], false, 'valueaaaaaaa', 'Value'), '329.00', false, null, null, $text->currencyName('USD')),
            self::slot(self::field('currency', ['code' => 'XYZ', 'minor_digits' => 2], false, 'valuebbbbbbb', 'Price'), '', false, null, null, $text->currencyName('XYZ')),
        ];
        $xpath = self::xpath(self::render(['fields' => $slots]));

        self::assertSame('Value (US dollars)', self::norm(self::one($xpath, '//label[@for="f-valueaaaaaaa"]')->textContent));
        self::assertSame('Price (XYZ)', self::norm(self::one($xpath, '//label[@for="f-valuebbbbbbb"]')->textContent));
        self::assertSame('decimal', self::one($xpath, '//*[@id="f-valueaaaaaaa"]')->getAttribute('inputmode'));
    }

    public function testDurationHelp(): void
    {
        $slots = [
            self::slot(self::field('duration', [], false, 'durationaaaa'), '', false, 'Time spent.'),
            self::slot(self::field('duration', ['seconds' => true], false, 'durationbbbb'), '', false, 'Time spent.'),
        ];
        $xpath = self::xpath(self::render(['fields' => $slots]));

        self::assertSame('Time spent. ' . self::text('input.duration.help'), self::norm(self::one($xpath, '//*[@id="help-durationaaaa"]')->textContent));
        self::assertSame('Time spent. ' . self::text('input.duration.help_seconds'), self::norm(self::one($xpath, '//*[@id="help-durationbbbb"]')->textContent));
    }

    public function testNoInputForTypesWithoutOne(): void
    {
        foreach (['auto_number', 'relation', 'attachment'] as $type) {
            $xpath = self::xpath(self::render(['fields' => [self::slot(self::field($type))]]));
            self::assertSame(0, $xpath->query('//form//input[@type!="hidden"] | //form//select | //form//textarea')->length, $type);
        }
    }

    // value() and ValueText.

    /**
     * Type, options, value, expected text, expected cell.
     *
     * @return iterable<string, array{string, array<string, mixed>, mixed, string, string}>
     */
    public static function displayedValues(): iterable
    {
        $long = str_repeat('abcdefghij', 9);
        $cut = substr($long, 0, 79) . "\u{2026}";
        yield 'text' => ['text', [], 'Stand mixer', 'Stand mixer', 'Stand mixer'];
        yield 'long text in a cell is cut' => ['text', [], $long, $long, $cut];
        yield 'exactly 80 characters' => ['text', [], substr($long, 0, 80), substr($long, 0, 80), substr($long, 0, 80)];
        yield 'multibyte text is cut by characters' => ['text', [], str_repeat("\u{00E9}", 81), str_repeat("\u{00E9}", 81), str_repeat("\u{00E9}", 79) . "\u{2026}"];
        yield 'empty text' => ['text', [], null, '', ''];
        yield 'long text' => ['long_text', [], "First line\nSecond line", "First line\nSecond line", 'First line'];
        yield 'number' => ['number', ['decimals' => 1], 1234.5, '1,234.5', '1,234.5'];
        yield 'whole number' => ['number', ['decimals' => 0], 1234567, '1,234,567', '1,234,567'];
        yield 'empty number' => ['number', [], null, '', ''];
        yield 'dollars' => ['currency', ['code' => 'USD', 'minor_digits' => 2], 32900, '$329.00', '$329.00'];
        yield 'negative dollars' => ['currency', ['code' => 'USD', 'minor_digits' => 2], -500, '-$5.00', '-$5.00'];
        yield 'francs' => ['currency', ['code' => 'CHF', 'minor_digits' => 2], 1200, 'CHF 12.00', 'CHF 12.00'];
        yield 'date' => ['date', [], '2025-03-14', '14 Mar 2025', '14 Mar 2025'];
        yield 'time' => ['time', ['seconds' => false], '14:30:00', '14:30', '14:30'];
        yield 'time with seconds' => ['time', ['seconds' => true], '14:30:15', '14:30:15', '14:30:15'];
        yield 'date and time' => ['date_time', ['seconds' => false], '2025-03-14T14:30:00', '14 Mar 2025, 14:30', '14 Mar 2025, 14:30'];
        yield 'duration' => ['duration', ['seconds' => false], 5400, '1:30', '1:30'];
        yield 'long duration with seconds' => ['duration', ['seconds' => true], 90061, '25:01:01', '25:01:01'];
        yield 'ticked' => ['checkbox', [], true, 'Yes', 'Yes'];
        yield 'not ticked' => ['checkbox', [], false, 'No', 'No'];
        yield 'choice' => ['choice', [], 'Appliance', 'Appliance', 'Appliance'];
        yield 'rating' => ['rating', ['max' => 5], 4, '4 of 5', '4 of 5'];
        yield 'empty rating' => ['rating', ['max' => 5], null, '', ''];
        yield 'web address' => ['url', [], 'https://example.com/manual', 'https://example.com/manual', 'https://example.com/manual'];
        yield 'email' => ['email', [], 'user@example.com', 'user@example.com', 'user@example.com'];
        yield 'phone' => ['phone', [], '+1 555-0100', '+1 555-0100', '+1 555-0100'];
        yield 'auto-number' => ['auto_number', [], 1234, '1234', '1234'];
        yield 'markup' => ['text', [], self::HOSTILE, self::HOSTILE, self::HOSTILE];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('displayedValues')]
    public function testValueHelperInBothModes(string $type, array $options, mixed $value, string $text, string $cell): void
    {
        $field = self::field($type, $options);
        $html = self::render(['values' => [
            ['field' => $field, 'value' => $value, 'mode' => 'text'],
            ['field' => $field, 'value' => $value, 'mode' => 'cell'],
        ]]);
        $items = self::xpath($html)->query('//ul[@class="link-list"]/li');
        self::assertSame(2, $items->length);
        self::assertSame($text, $items->item(0)?->textContent);
        self::assertSame($cell, $items->item(1)?->textContent);
        self::assertSame($text, self::valueText()->display($field, $value));
        self::assertSame($cell, self::valueText()->display($field, $value, 'cell'));
    }

    public function testEveryTypeWithAColumnIsDisplayed(): void
    {
        $types = array_unique(array_column(iterator_to_array(self::displayedValues()), 0));
        sort($types);
        $expected = [...ValueText::EDITABLE, 'auto_number'];
        sort($expected);
        self::assertSame($expected, array_values($types));
    }

    public function testAttachmentAndRelationText(): void
    {
        $text = self::valueText();
        self::assertSame('None', $text->display(self::field('attachment'), 0));
        self::assertSame('None', $text->display(self::field('attachment'), null, 'cell'));
        self::assertSame('1 file', $text->display(self::field('attachment'), 1));
        self::assertSame('1,200 files', $text->display(self::field('attachment'), 1200));
        self::assertSame('None', $text->display(self::field('relation'), []));
        self::assertSame('Kitchen', $text->display(self::field('relation'), ['Kitchen']));
        self::assertSame('A, B, C', $text->display(self::field('relation'), ['A', 'B', 'C']));
        self::assertSame('A, B, C and 2 more', $text->display(self::field('relation'), ['A', 'B', 'C', 'D', 'E']));
    }

    public function testInputValue(): void
    {
        $text = self::valueText();
        self::assertSame('14:30', $text->input(self::field('time'), '14:30:00', false));
        self::assertSame('14:30:00', $text->input(self::field('time'), '14:30:00', true));
        self::assertSame('14:30:15', $text->input(self::field('time', ['seconds' => true]), '14:30:15', false));
        self::assertSame('2025-03-14T14:30', $text->input(self::field('date_time'), '2025-03-14T14:30:00', false));
        self::assertSame('100:05', $text->input(self::field('duration'), '100:05:00', false));
        self::assertSame('soon', $text->input(self::field('time'), 'soon', false));
        self::assertSame(self::HOSTILE, $text->input(self::field('text'), self::HOSTILE, false));
    }

    public function testCurrencyName(): void
    {
        $text = self::valueText();
        self::assertSame('US dollars', $text->currencyName('USD'));
        self::assertSame('Indian rupees', $text->currencyName('INR'));
        self::assertSame('XYZ', $text->currencyName('XYZ'));
        self::assertSame('usd', $text->currencyName('usd'));
    }

    public function testDisplayRefusesWrongValueKinds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::valueText()->display(self::field('currency'), '12.00');
    }

    public function testDisplayRefusesAnUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::valueText()->display(self::field('text'), 'x', 'html');
    }

    // record-table.

    /** @return array<string, mixed> */
    private static function table(): array
    {
        $title = self::field('text', [], false, 'titleaaaaaaa', 'Name');
        $room = self::field('relation', [], false, 'roomaaaaaaaa', 'Room');
        $value = self::field('currency', ['code' => 'USD', 'minor_digits' => 2], false, 'valueaaaaaaa', 'Value');
        $receipt = self::field('attachment', [], false, 'receiptaaaaa', 'Receipt');
        $notes = self::field('long_text', [], false, 'notesaaaaaaa', 'Notes');
        $link = static fn (int $id, ?string $title): array => [
            'path' => '/projects/abcdefghijkl/tables/roomstableaa/records/' . $id, 'table' => 'roomstableaa', 'id' => $id, 'title' => $title,
        ];
        $cells = static fn (array $links, int $more, ?int $files, ?int $money, ?string $note) => [
            ['field' => $room, 'value' => null, 'links' => $links, 'moreLinks' => $more, 'fileCount' => null],
            ['field' => $value, 'value' => $money, 'links' => null, 'moreLinks' => 0, 'fileCount' => null],
            ['field' => $receipt, 'value' => null, 'links' => null, 'moreLinks' => 0, 'fileCount' => $files],
            ['field' => $notes, 'value' => $note, 'links' => null, 'moreLinks' => 0, 'fileCount' => null],
        ];

        return [
            'caption' => 'Items, 1 to 2 of 2 shown',
            'columns' => [
                ['field' => $title, 'header' => 'Name (A to Z)', 'numeric' => false, 'sort' => 'ascending'],
                ['field' => $room, 'header' => 'Room', 'numeric' => false, 'sort' => null],
                ['field' => $value, 'header' => 'Value', 'numeric' => true, 'sort' => null],
                ['field' => $receipt, 'header' => 'Receipt', 'numeric' => false, 'sort' => null],
                ['field' => $notes, 'header' => self::HOSTILE, 'numeric' => false, 'sort' => 'descending'],
            ],
            'rows' => [
                [
                    'path' => '/projects/abcdefghijkl/tables/' . self::TABLE . '/records/9',
                    'query' => ['q' => 'mix', 'sort' => 'f_titleaaaaaaa.asc'],
                    'title' => 'Stand mixer',
                    'cells' => $cells([$link(1, 'Kitchen')], 0, 1, 32900, "Dough hook\nSpare bowl"),
                ],
                [
                    'path' => '/projects/abcdefghijkl/tables/' . self::TABLE . '/records/10',
                    'query' => [],
                    'title' => self::HOSTILE,
                    'cells' => $cells([$link(2, self::HOSTILE), $link(3, null), $link(4, 'Garage')], 2, 0, null, null),
                ],
                [
                    'path' => '/projects/abcdefghijkl/tables/' . self::TABLE . '/records/11',
                    'query' => [],
                    'title' => 'Item 11',
                    'cells' => $cells([], 0, 3, -500, ''),
                ],
            ],
            'itemNames' => ['roomstableaa' => 'Room'],
        ];
    }

    public function testRecordTable(): void
    {
        $html = self::render(['table' => self::table()], '/base');
        $xpath = self::xpath($html);

        self::one($xpath, '//div[@class="table-wrap"]/table[@class="data-table data-table-plain"]');
        self::assertSame('Items, 1 to 2 of 2 shown', self::one($xpath, '//table/caption[@class="visually-hidden"]')->textContent);

        $headers = [];
        foreach ($xpath->query('//thead/tr/th') as $th) {
            /** @var DOMElement $th */
            $headers[] = [self::norm($th->textContent), $th->getAttribute('scope'), $th->getAttribute('class'), $th->getAttribute('aria-sort')];
        }
        self::assertSame(
            [
                ['Name (A to Z)', 'col', '', 'ascending'],
                ['Room', 'col', '', ''],
                ['Value', 'col', 'num', ''],
                ['Receipt', 'col', '', ''],
                [self::HOSTILE, 'col', '', 'descending'],
            ],
            $headers,
        );

        $rows = [];
        foreach ($xpath->query('//tbody/tr') as $tr) {
            /** @var DOMElement $tr */
            $first = $xpath->query('./*[1]', $tr)->item(0);
            self::assertInstanceOf(DOMElement::class, $first);
            self::assertSame(['th', 'row'], [$first->nodeName, $first->getAttribute('scope')]);
            $a = $xpath->query('./a[@class="cell-title"]', $first)->item(0);
            self::assertInstanceOf(DOMElement::class, $a);
            $row = [[$a->getAttribute('href'), $a->textContent]];
            foreach ($xpath->query('./td', $tr) as $td) {
                /** @var DOMElement $td */
                $links = [];
                foreach ($xpath->query('./a', $td) as $link) {
                    /** @var DOMElement $link */
                    $links[] = $link->getAttribute('href') . ' ' . $link->textContent;
                }
                $none = $xpath->query('./span[@class="cell-none"]', $td)->length;
                $row[] = [$td->getAttribute('class'), $td->textContent, $links, $none];
            }
            $rows[] = $row;
        }
        $roomPath = '/base/projects/abcdefghijkl/tables/roomstableaa/records/';
        self::assertSame(
            [
                [
                    ['/base/projects/abcdefghijkl/tables/' . self::TABLE . '/records/9?q=mix&sort=f_titleaaaaaaa.asc', 'Stand mixer'],
                    ['', 'Kitchen', [$roomPath . '1 Kitchen'], 0],
                    ['num', '$329.00', [], 0],
                    ['', '1 file', [], 0],
                    ['', 'Dough hook', [], 0],
                ],
                [
                    ['/base/projects/abcdefghijkl/tables/' . self::TABLE . '/records/10', self::HOSTILE],
                    ['', self::HOSTILE . ', Room 3, Garage and 2 more', [$roomPath . '2 ' . self::HOSTILE, $roomPath . '3 Room 3', $roomPath . '4 Garage'], 0],
                    ['num', '', [], 0],
                    ['', 'None', [], 1],
                    ['', '', [], 0],
                ],
                [
                    ['/base/projects/abcdefghijkl/tables/' . self::TABLE . '/records/11', 'Item 11'],
                    ['', 'None', [], 1],
                    ['num', '-$5.00', [], 0],
                    ['', '3 files', [], 0],
                    ['', '', [], 0],
                ],
            ],
            $rows,
        );
    }

    // pager.

    /** @return array<string, mixed> */
    private static function pager(int $page, int $pages, int $total): array
    {
        $from = ($page - 1) * 50 + 1;

        return [
            'from' => $from, 'to' => min($total, $page * 50), 'total' => $total, 'page' => $page, 'pages' => $pages,
            'path' => '/projects/abcdefghijkl/tables/' . self::TABLE, 'query' => ['q' => self::HOSTILE, 'sort' => 'added.desc'],
        ];
    }

    public function testPagerOnASinglePage(): void
    {
        $xpath = self::xpath(self::render(['pager' => self::pager(1, 1, 48)]));

        $nav = self::one($xpath, '//nav[@class="pager"]');
        self::assertSame(self::text('pager.label'), $nav->getAttribute('aria-label'));
        self::assertSame('Showing 1 to 48 of 48', self::one($xpath, '//nav/span[@class="pager-status"]')->textContent);
        $buttons = [];
        foreach ($xpath->query('//nav/div[@class="pager-links"]/*') as $button) {
            /** @var DOMElement $button */
            $buttons[] = [$button->nodeName, $button->getAttribute('class'), $button->getAttribute('aria-disabled'), $button->hasAttribute('href'), $button->textContent];
        }
        self::assertSame(
            [['span', 'button pager-link', 'true', false, 'Previous'], ['span', 'button pager-link', 'true', false, 'Next']],
            $buttons,
        );
    }

    public function testPagerInTheMiddle(): void
    {
        $xpath = self::xpath(self::render(['pager' => self::pager(2, 30, 1480)]));

        self::assertSame('Showing 51 to 100 of 1,480', self::one($xpath, '//nav/span[@class="pager-status"]')->textContent);
        $prev = self::one($xpath, '//nav//a[@rel="prev"]');
        $next = self::one($xpath, '//nav//a[@rel="next"]');
        $query = '&q=' . rawurlencode(self::HOSTILE) . '&sort=added.desc';
        self::assertSame('/projects/abcdefghijkl/tables/' . self::TABLE . '?page=1' . $query, $prev->getAttribute('href'));
        self::assertSame('/projects/abcdefghijkl/tables/' . self::TABLE . '?page=3' . $query, $next->getAttribute('href'));
        self::assertSame(['button pager-link', 'Previous'], [$prev->getAttribute('class'), $prev->textContent]);
        self::assertSame(0, $xpath->query('//nav//span[@aria-disabled]')->length);
    }

    public function testPagerOnTheLastPage(): void
    {
        $xpath = self::xpath(self::render(['pager' => self::pager(30, 30, 1480)]));

        self::one($xpath, '//nav//a[@rel="prev"]');
        self::assertSame('Next', self::one($xpath, '//nav//span[@aria-disabled="true"]')->textContent);
    }

    // empty-state.

    public function testEmptyState(): void
    {
        $data = [
            'title' => 'pager.showing', 'titleParams' => ['from' => self::HOSTILE, 'to' => 1, 'total' => 2],
            'body' => 'input.links.help', 'bodyParams' => ['table' => self::HOSTILE],
            'link' => ['label' => 'notice.undo_label', 'labelParams' => ['title' => '{name}'], 'path' => '/projects', 'query' => ['sort' => 'added.desc']],
        ];
        $xpath = self::xpath(self::render(['empty' => $data]));

        $box = self::one($xpath, '//div[@class="empty-state"]');
        $parts = [];
        foreach ($xpath->query('./*', $box) as $child) {
            /** @var DOMElement $child */
            $parts[] = [$child->nodeName, $child->getAttribute('class'), $child->textContent];
        }
        self::assertSame(
            [
                ['p', 'empty-state-title', 'Showing ' . self::HOSTILE . ' to 1 of 2'],
                ['p', '', 'Linked to the ' . self::HOSTILE . ' table.'],
                ['a', '', 'Undo: restore {name}'],
            ],
            $parts,
        );
        self::assertSame('/projects?sort=added.desc', self::one($xpath, '//div[@class="empty-state"]/a')->getAttribute('href'));

        unset($data['link']);
        $without = self::xpath(self::render(['empty' => $data]));
        self::assertSame(0, $without->query('//div[@class="empty-state"]/a')->length);
        self::assertSame(0, $without->query('//div[@class="empty-state"]//*[contains(@class, "button")]')->length, 'No button in an empty state.');
    }

    // form-inline.

    public function testFormInline(): void
    {
        $nodes = [
            ['kind' => 'text', 'text' => 'Keep the '],
            ['kind' => 'strong', 'children' => [['kind' => 'text', 'text' => 'receipt']]],
            ['kind' => 'text', 'text' => ' & the '],
            ['kind' => 'em', 'children' => [['kind' => 'text', 'text' => 'box '], ['kind' => 'strong', 'children' => [['kind' => 'text', 'text' => self::HOSTILE]]]]],
            ['kind' => 'br'],
            ['kind' => 'link', 'children' => [['kind' => 'text', 'text' => 'the manual']]],
            ['kind' => 'image'],
            ['kind' => 'text', 'text' => '.'],
        ];
        $html = self::render(['inline' => $nodes]);
        $xpath = self::xpath($html);

        $p = self::one($xpath, '//main/p[@class="lead"]');
        self::assertSame('Keep the receipt & the box ' . self::HOSTILE . 'the manual.', $p->textContent, 'No whitespace is added between nodes.');
        self::assertSame('receipt', self::one($xpath, '//p[@class="lead"]/strong')->textContent);
        self::assertSame(self::HOSTILE, self::one($xpath, '//p[@class="lead"]/em/strong')->textContent);
        self::assertSame(1, $xpath->query('//p[@class="lead"]/br')->length);
        self::assertSame(0, $xpath->query('//p[@class="lead"]//a | //p[@class="lead"]//img')->length, 'Links are shown as text; images are dropped.');
    }

    // Ids and the id() helper.

    public function testIdHelperOutputsTheValidatedId(): void
    {
        self::assertSame('f-abcdefghijkl', Ids::make('f', 'abcdefghijkl'));
        self::assertSame('group-abcdefghijkl-12', Ids::make('group', 'abcdefghijkl', 12));
        $xpath = self::xpath(self::render(['fields' => [self::slot(self::field('text'))]]));
        self::assertSame('f-abcdefghijkl', self::one($xpath, '//label')->getAttribute('for'));
    }

    // T-WEB-42: the shared M3 partials.

    /** @param array<string, mixed> $data */
    private static function renderM3(array $data): string
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        $view = (new View(
            self::$templates,
            self::strings(),
            new UrlGenerator('', self::VERSION),
            Appearance::default(),
            $config,
        ))->withCsrfToken(self::TOKEN)->withFormat(self::format());
        $html = $view->page('m3-components', ['title' => 'Components', ...$data])->body;

        HtmlAssertions::assertWellFormed($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
        HtmlAssertions::assertForm($html);
        self::assertStringNotContainsString('<script>alert', $html, 'Markup in values is escaped.');
        self::assertStringNotContainsString('&amp;lt;', $html, 'Nothing is escaped twice.');
        self::assertStringNotContainsString('&amp;amp;', $html, 'Nothing is escaped twice.');

        return $html;
    }

    public function testStepsMarkTheCurrentStep(): void
    {
        $xpath = self::xpath(self::renderM3(['steps' => [
            'label' => 'steps.import.label',
            'steps' => [
                ['label' => 'steps.import.choose', 'state' => 'done'],
                ['label' => 'steps.import.check', 'state' => 'now'],
                ['label' => 'steps.import.import', 'state' => 'next'],
                ['label' => 'steps.import.report', 'state' => 'unexpected'],
            ],
        ]]));

        $list = self::one($xpath, '//main/ol');
        self::assertSame('steps', $list->getAttribute('class'));
        self::assertSame('Import steps', $list->getAttribute('aria-label'));
        $items = $xpath->query('//main/ol/li');
        self::assertSame(4, $items->length);
        $expected = [
            ['step step-done', 'Step 1, done', 'Choose a file'],
            ['step step-now', 'Step 2, now', 'Check the fields'],
            ['step step-next', 'Step 3', 'Import'],
            ['step step-next', 'Step 4', 'Read the report'],
        ];
        foreach ($expected as $n => [$class, $number, $label]) {
            $item = $items->item($n);
            self::assertInstanceOf(DOMElement::class, $item);
            self::assertSame($class, $item->getAttribute('class'));
            self::assertSame($number, self::norm((string) $xpath->evaluate('string(span[@class="step-number"])', $item)));
            self::assertSame($label, self::norm((string) $xpath->evaluate('string(span[@class="step-label"])', $item)));
            self::assertSame($number . ' ' . $label, self::norm($item->textContent));
        }
        self::assertSame(1, $xpath->query('//li[@aria-current]')->length);
        self::assertSame('step', self::one($xpath, '//li[contains(@class, "step-now")]')->getAttribute('aria-current'));
    }

    /** @return array<string, mixed> */
    private static function fileField(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'file',
            'label' => 'sidebar.import',
            'help' => 'error.too_large.body',
            'helpParams' => ['max' => self::HOSTILE],
            'accept' => UploadedFile::acceptList(['.csv', 'txt', 'csv']),
            'problems' => [],
        ];
    }

    public function testFileFieldLabelHelpAndAccept(): void
    {
        $html = self::renderM3(['file' => self::fileField()]);
        $xpath = self::xpath($html);

        $form = self::one($xpath, '//main/form');
        self::assertSame('multipart/form-data', $form->getAttribute('enctype'));
        self::assertSame('post', $form->getAttribute('method'));
        self::assertSame(self::TOKEN, self::one($xpath, '//form/input[@name="_csrf"]')->getAttribute('value'));

        $input = self::one($xpath, '//input[@type="file"]');
        self::assertSame([
            'accept' => '.csv,.txt',
            'aria-describedby' => 'help-file',
            'class' => 'input',
            'id' => 'f-file',
            'name' => 'file',
            'required' => 'required',
            'type' => 'file',
        ], self::attributes($input));
        self::assertSame('Import a file', self::one($xpath, '//label[@for="f-file"]')->textContent);
        $help = self::one($xpath, '//p[@id="help-file"]');
        self::assertSame('help', $help->getAttribute('class'));
        self::assertSame('The largest file this installation accepts is ' . self::HOSTILE . '.', $help->textContent);
        self::assertSame(0, $xpath->query('//*[@id="err-file"]')->length);
        // Label, help, then the input, in reading order (section 9.1, item 4).
        self::assertSame(['label', 'p', 'input'], array_map(
            static fn (\DOMNode $n): string => $n->nodeName,
            iterator_to_array($xpath->query('//div[@class="field"]/*')),
        ));
    }

    public function testFileFieldWithProblemsAndWithoutAccept(): void
    {
        $xpath = self::xpath(self::renderM3(['file' => self::fileField([
            'accept' => [],
            'problems' => [self::HOSTILE, 'That file is larger than 64.0 MB, the largest this installation accepts.'],
        ])]));

        $input = self::one($xpath, '//input[@type="file"]');
        self::assertFalse($input->hasAttribute('accept'));
        self::assertSame('true', $input->getAttribute('aria-invalid'));
        self::assertSame('help-file err-file', $input->getAttribute('aria-describedby'));
        $error = self::one($xpath, '//p[@id="err-file"]');
        self::assertSame('error-text', $error->getAttribute('class'));
        self::assertSame(self::HOSTILE . ' That file is larger than 64.0 MB, the largest this installation accepts.', $error->textContent);
        self::assertSame(['label', 'p', 'p', 'input'], array_map(
            static fn (\DOMNode $n): string => $n->nodeName,
            iterator_to_array($xpath->query('//div[@class="field"]/*')),
        ));

        $withAccept = self::xpath(self::renderM3(['file' => self::fileField(['problems' => ['x']])]));
        self::assertSame('.csv,.txt', self::one($withAccept, '//input[@type="file"]')->getAttribute('accept'));
        self::assertSame('true', self::one($withAccept, '//input[@type="file"]')->getAttribute('aria-invalid'));
    }

    public function testFileFieldEscapesAHostileAcceptList(): void
    {
        // The controllers build the list with UploadedFile::acceptList(),
        // which refuses this; the partial still escapes whatever it gets.
        $xpath = self::xpath(self::renderM3(['file' => self::fileField(['accept' => ['.csv" onclick="alert(1)', '<b>']])]));

        self::assertSame('.csv" onclick="alert(1),<b>', self::one($xpath, '//input[@type="file"]')->getAttribute('accept'));
        self::assertFalse(self::one($xpath, '//input[@type="file"]')->hasAttribute('onclick'));
    }

    /** Review follow-up: field names with digits (and, with an id part, underscores) work from request to form. */
    public function testFieldNamesWithDigitsWorkEndToEnd(): void
    {
        $files = Request::uploadedFiles(
            ['file2' => ['name' => 'a.csv', 'tmp_name' => '/tmp/phpA', 'size' => 3, 'error' => UPLOAD_ERR_OK], 'data_file' => ['name' => 'b.csv', 'tmp_name' => '/tmp/phpB', 'size' => 3, 'error' => UPLOAD_ERR_OK]],
            static fn (string $path): bool => true,
        );
        self::assertSame(['file2', 'data_file'], array_keys($files));

        $xpath = self::xpath(self::renderM3(['file' => self::fileField(['name' => $files['file2']->field])]));
        $input = self::one($xpath, '//input[@type="file"]');
        self::assertSame('file2', $input->getAttribute('name'));
        self::assertSame('f-file2', $input->getAttribute('id'));
        self::assertSame('help-file2', $input->getAttribute('aria-describedby'));
        self::assertSame('f-file2', self::one($xpath, '//label')->getAttribute('for'));
        self::assertSame(Ids::make('f', 'file2'), $input->getAttribute('id'));

        $xpath = self::xpath(self::renderM3(['file' => self::fileField(['name' => $files['data_file']->field, 'id' => 'datafile', 'problems' => ['x']])]));
        $input = self::one($xpath, '//input[@type="file"]');
        self::assertSame('data_file', $input->getAttribute('name'));
        self::assertSame('f-datafile', $input->getAttribute('id'));
        self::assertSame('help-datafile err-datafile', $input->getAttribute('aria-describedby'));
        self::assertSame('f-datafile', self::one($xpath, '//label')->getAttribute('for'));
    }

    public function testFileFieldRefusesANameThatIsNotAnIdPart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::renderM3(['file' => self::fileField(['name' => 'file" autofocus="autofocus'])]);
    }

    public function testFactsPanel(): void
    {
        $xpath = self::xpath(self::renderM3(['facts' => [
            'heading' => 'sidebar.data',
            'id' => 'file',
            'items' => [
                ['term' => 'sidebar.project', 'value' => self::HOSTILE],
                ['term' => 'sidebar.tables', 'value' => '52 rows, plus a heading row'],
            ],
        ]]));

        $section = self::one($xpath, '//main/section');
        self::assertSame('panel', $section->getAttribute('class'));
        self::assertSame('i-file', $section->getAttribute('aria-labelledby'));
        self::assertSame('Data', self::one($xpath, '//section/h2[@id="i-file"]')->textContent);
        self::assertSame('facts', self::one($xpath, '//section/dl')->getAttribute('class'));
        $pairs = $xpath->query('//dl/div');
        self::assertSame(2, $pairs->length);
        self::assertSame(['Project', self::HOSTILE], [
            (string) $xpath->evaluate('string(//dl/div[1]/dt)'),
            (string) $xpath->evaluate('string(//dl/div[1]/dd)'),
        ]);
        self::assertSame('52 rows, plus a heading row', (string) $xpath->evaluate('string(//dl/div[2]/dd)'));
    }

    public function testWarningPanel(): void
    {
        $xpath = self::xpath(self::renderM3(['warnings' => [
            'heading' => 'count.problems.many',
            'headingParams' => ['count' => 2],
            'items' => [
                ['key' => 'problem.upload.too_large', 'params' => ['max' => self::HOSTILE]],
                ['key' => 'problem.upload.failed', 'params' => []],
            ],
        ]]));

        $section = self::one($xpath, '//main/section');
        self::assertSame('warning-panel', $section->getAttribute('class'));
        $heading = self::one($xpath, '//section/h2');
        self::assertSame($section->getAttribute('aria-labelledby'), $heading->getAttribute('id'));
        self::assertSame('There are 2 problems', $heading->textContent);
        $items = $xpath->query('//section/ul/li');
        self::assertSame(2, $items->length);
        self::assertSame('That file is larger than ' . self::HOSTILE . ', the largest this installation accepts.', $items->item(0)?->textContent);
        self::assertSame('The file did not arrive completely. Try again.', $items->item(1)?->textContent);
    }

    public function testProgressWithAndWithoutATotal(): void
    {
        $data = [
            'label' => 'shell.title',
            'labelParams' => ['title' => self::HOSTILE],
            'done' => 4000,
            'total' => 10000,
            'status' => 'size.bytes',
            'statusParams' => ['count' => 4000],
        ];
        $xpath = self::xpath(self::renderM3(['progress' => $data]));

        self::assertSame('progress', self::one($xpath, '//main/div')->getAttribute('class'));
        $label = self::one($xpath, '//div[@class="progress"]/p[1]');
        self::assertSame(self::HOSTILE . ' - TiffinDB', $label->textContent);
        $bar = self::one($xpath, '//progress');
        self::assertSame($label->getAttribute('id'), $bar->getAttribute('aria-labelledby'));
        self::assertSame('10000', $bar->getAttribute('max'));
        self::assertSame('4000', $bar->getAttribute('value'));
        $status = self::one($xpath, '//p[@class="progress-status"]');
        self::assertSame('status', $status->getAttribute('role'));
        self::assertSame('4,000 bytes', $status->textContent);
        // The status follows the bar.
        self::assertSame(1, $xpath->query('//progress/following-sibling::p[@role="status"]')->length);

        $unknown = self::xpath(self::renderM3(['progress' => ['total' => null] + $data]));
        $bar = self::one($unknown, '//progress');
        self::assertFalse($bar->hasAttribute('max'), 'No max without a total.');
        self::assertFalse($bar->hasAttribute('value'), 'No value without a total: the bar is indeterminate.');
        self::assertSame('progress-label', $bar->getAttribute('aria-labelledby'));
    }

    public function testContinueFormAutoAndManual(): void
    {
        $auto = self::xpath(self::renderM3(['continue' => [
            'action' => '/projects/abcdefghijkl/import/mnopqrstuvwx/continue',
            'label' => 'sidebar.import',
            'auto' => true,
            'working' => 'sidebar.export',
        ]]));
        $form = self::one($auto, '//main/form');
        self::assertSame([
            'action' => '/projects/abcdefghijkl/import/mnopqrstuvwx/continue',
            'class' => 'continue-form',
            'data-auto-continue' => '1',
            'method' => 'post',
        ], self::attributes($form));
        self::assertSame(self::TOKEN, self::one($auto, '//form/input[@name="_csrf"]')->getAttribute('value'));
        $button = self::one($auto, '//form/button');
        self::assertSame(['class' => 'button button-primary button-large', 'data-working' => 'Export', 'type' => 'submit'], self::attributes($button));
        self::assertSame('Import a file', $button->textContent);

        $manual = self::xpath(self::renderM3(['continue' => [
            'action' => '/projects/abcdefghijkl/import/mnopqrstuvwx/continue',
            'label' => 'sidebar.export',
            'auto' => false,
        ]]));
        self::assertFalse(self::one($manual, '//main/form')->hasAttribute('data-auto-continue'), 'Saving waits for a press.');
        self::assertFalse(self::one($manual, '//form/button')->hasAttribute('data-working'));
    }

    public function testAcceptListIsChecked(): void
    {
        self::assertSame(['.csv', '.txt', '.xlsx'], UploadedFile::acceptList(['csv', '.txt', '.csv', 'xlsx']));
        self::assertSame([], UploadedFile::acceptList([]));
        foreach (['', '.', '..csv', '.CSV', '.c s v', '.csv,', 'x"y', '.abcdefghijk', "csv\n", '.cs/v'] as $bad) {
            try {
                UploadedFile::acceptList([$bad]);
                self::fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // T-WEB-56: the shared M4 partials (M4 spec part 2 section 6.1).

    private const FIELD = 'abcdefghijkl';

    private const RECORD = '/projects/abcdefghijkl/tables/mnopqrstuvwx/records/12';

    /** @param array<string, mixed> $data */
    private static function renderM4(array $data): string
    {
        $config = require RepoFiles::path('config/app.php');
        self::assertIsArray($config);
        $view = (new View(
            self::$templates,
            self::strings(),
            new UrlGenerator('', self::VERSION),
            Appearance::default(),
            $config,
        ))->withCsrfToken(self::TOKEN)->withFormat(self::format());
        $html = $view->page('m4-components', ['title' => 'Components', ...$data])->body;

        HtmlAssertions::assertWellFormed($html);
        HtmlAssertions::assertNoInlineCode($html, self::sourceUrl());
        HtmlAssertions::assertForm($html);
        self::assertStringNotContainsString('<script>alert', $html, 'Markup in values is escaped.');
        self::assertStringNotContainsString('&amp;lt;', $html, 'Nothing is escaped twice.');
        self::assertStringNotContainsString('&amp;amp;', $html, 'Nothing is escaped twice.');

        return $html;
    }

    /** @return array<string, mixed> */
    private static function oneLink(array $overrides = []): array
    {
        return $overrides + [
            'key' => self::FIELD,
            'link' => ['path' => '/projects/abcdefghijkl/tables/ghijklmnopqr/records/3', 'title' => 'Kitchen'],
            'choosePath' => self::RECORD . '/links/' . self::FIELD,
            'item' => 'room',
            'table' => 'Rooms',
            'required' => false,
            'isNew' => false,
        ];
    }

    /** @return list<string> The normalised text of each match. */
    private static function texts(DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = self::norm((string) $node->textContent);
        }

        return $texts;
    }

    public function testLinkControlWithALink(): void
    {
        $xpath = self::xpath(self::renderM4(['one' => self::oneLink()]));

        $control = self::one($xpath, '//div[@class="field"]/div[@class="link-control"]');
        $chosen = self::one($xpath, '//div[@class="link-control"]/a[@class="link-chosen"]');
        self::assertSame('Kitchen', $chosen->textContent);
        self::assertSame('/projects/abcdefghijkl/tables/ghijklmnopqr/records/3', $chosen->getAttribute('href'));
        $choose = self::one($xpath, '//div[@class="link-control"]/a[@class="button button-plain"]');
        self::assertSame('Choose a different room', self::norm($choose->textContent));
        self::assertSame(self::RECORD . '/links/' . self::FIELD, $choose->getAttribute('href'));
        self::assertSame('f-' . self::FIELD, $choose->getAttribute('aria-describedby'));
        self::one($xpath, '//p[@id="f-' . self::FIELD . '"]');
        self::assertSame(0, $xpath->query('//span[@class="link-none"]')->length);
        self::assertSame(['a', 'a'], array_map(static fn (\DOMNode $n): string => $n->nodeName, iterator_to_array($xpath->query('*', $control))));
        self::assertSame(
            ['Linked to the Rooms table.', 'Save your changes first: the buttons here leave this page.'],
            self::texts($xpath, '//div[@class="field"]/p[contains(@class, "help")]'),
        );
        self::one($xpath, '//div[@class="field"]/p[@class="help leave-note"]');
    }

    public function testLinkControlWithoutALinkAndWithoutEditing(): void
    {
        $xpath = self::xpath(self::renderM4(['one' => self::oneLink(['link' => null])]));
        self::assertSame('No room chosen', self::one($xpath, '//div[@class="link-control"]/span[@class="link-none"]')->textContent);
        self::assertSame('Choose a record', self::norm(self::one($xpath, '//div[@class="link-control"]/a[@class="button button-plain"]')->textContent));
        self::assertSame(0, $xpath->query('//a[@class="link-chosen"]')->length);

        $readOnly = self::xpath(self::renderM4(['one' => self::oneLink(['choosePath' => null])]));
        self::assertSame(['Kitchen'], self::texts($readOnly, '//div[@class="link-control"]/*'));
        self::assertSame(0, $readOnly->query('//*[contains(@class, "leave-note")]')->length, 'No leave note without controls.');
        self::assertSame(['Linked to the Rooms table.'], self::texts($readOnly, '//div[@class="field"]/p[contains(@class, "help")]'));
    }

    public function testLinkControlOnANewRecord(): void
    {
        $xpath = self::xpath(self::renderM4(['one' => self::oneLink(['link' => null, 'choosePath' => null, 'isNew' => true])]));
        self::assertSame(0, $xpath->query('//div[@class="link-control"]')->length);
        self::assertSame(0, $xpath->query('//*[contains(@class, "leave-note")]')->length);
        self::assertSame(
            ['Linked to the Rooms table.', 'You can choose one once this record is saved.'],
            self::texts($xpath, '//div[@class="field"]/p[contains(@class, "help")]'),
        );
    }

    public function testLinkControlEscapesHostileText(): void
    {
        $html = self::renderM4(['one' => self::oneLink([
            'link' => ['path' => '/projects/abcdefghijkl/tables/ghijklmnopqr/records/3', 'title' => self::HOSTILE],
            'item' => self::HOSTILE,
            'table' => self::HOSTILE,
        ])]);
        $xpath = self::xpath($html);
        self::assertSame(self::HOSTILE, self::one($xpath, '//a[@class="link-chosen"]')->textContent);
        self::assertSame('Choose a different ' . self::HOSTILE, self::one($xpath, '//a[@class="button button-plain"]')->textContent);
        self::assertSame('Linked to the ' . self::HOSTILE . ' table.', self::one($xpath, '//div[@class="field"]/p[@class="help"]')->textContent);

        $none = self::xpath(self::renderM4(['one' => self::oneLink(['link' => null, 'item' => self::HOSTILE])]));
        self::assertSame('No ' . self::HOSTILE . ' chosen', self::one($none, '//span[@class="link-none"]')->textContent);
    }

    public function testLinkControlTakesOnlyApplicationPaths(): void
    {
        foreach (['//example.org/x', 'javascript:alert(1)', 'https://example.org/', '/x?y=1'] as $path) {
            try {
                self::renderM4(['one' => self::oneLink(['choosePath' => $path])]);
                self::fail("Accepted {$path}");
            } catch (\Throwable $e) {
                self::assertInstanceOf(InvalidArgumentException::class, self::rootCause($e), $path);
            }
        }
    }

    private static function rootCause(\Throwable $e): \Throwable
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    /** @return array<string, mixed> */
    private static function manyLinks(array $overrides = []): array
    {
        return $overrides + [
            'key' => self::FIELD,
            'rows' => [
                ['path' => '/projects/abcdefghijkl/tables/stuvwxyz2345/records/7', 'title' => 'Stand mixer, manufacturer warranty', 'detail' => 'Ends 14 Mar 2027', 'target' => 7],
                ['path' => '/projects/abcdefghijkl/tables/stuvwxyz2345/records/9', 'title' => 'Extended cover', 'detail' => null, 'target' => 9],
            ],
            'unlink' => true,
            'linkPath' => self::RECORD . '/links/' . self::FIELD,
            'item' => 'warranty',
            'isNew' => false,
        ];
    }

    public function testLinkRowsWithUnlinkButtons(): void
    {
        $xpath = self::xpath(self::renderM4(['many' => self::manyLinks()]));

        $rows = $xpath->query('//div[@class="field"]/ul[@class="link-list"]/li[@class="link-row linked-row"]');
        self::assertSame(2, $rows->length);
        self::assertSame(['Stand mixer, manufacturer warranty', 'Extended cover'], self::texts($xpath, '//li/div[@class="linked-main"]/a'));
        self::assertSame(['/projects/abcdefghijkl/tables/stuvwxyz2345/records/7', '/projects/abcdefghijkl/tables/stuvwxyz2345/records/9'], self::texts($xpath, '//li/div[@class="linked-main"]/a/@href'));
        self::assertSame(['Ends 14 Mar 2027'], self::texts($xpath, '//li/div[@class="linked-main"]/span[@class="link-detail"]'));

        $buttons = $xpath->query('//li[@class="link-row linked-row"]/button');
        self::assertSame(2, $buttons->length);
        foreach ([[0, '7', 'Unlink Stand mixer, manufacturer warranty'], [1, '9', 'Unlink Extended cover']] as [$n, $target, $label]) {
            $button = $buttons->item($n);
            self::assertInstanceOf(DOMElement::class, $button);
            self::assertSame([
                'aria-label' => $label,
                'class' => 'button button-plain',
                'form' => 'unlink-' . self::FIELD,
                'name' => 'target',
                'type' => 'submit',
                'value' => $target,
            ], self::attributes($button));
            self::assertSame('Unlink', $button->textContent);
            self::assertStringStartsWith($button->textContent, $button->getAttribute('aria-label'), 'The accessible name starts with the visible text.');
        }
        // The unlink form the buttons name exists, outside the record form.
        self::one($xpath, '//form[@id="unlink-' . self::FIELD . '"][not(ancestor::form)]');
        self::assertSame(0, $xpath->query('//form//form')->length);

        $add = self::one($xpath, '//div[@class="field"]/div[@class="control-actions"]/a[@class="button button-strong"]');
        self::assertSame('Link another warranty', $add->textContent);
        self::assertSame(self::RECORD . '/links/' . self::FIELD, $add->getAttribute('href'));
        self::assertSame('f-' . self::FIELD, $add->getAttribute('aria-describedby'));
        self::assertSame(
            ['Unlinking does not delete the warranty record.', 'Save your changes first: the buttons here leave this page.'],
            self::texts($xpath, '//div[@class="field"]/p[contains(@class, "help")]'),
        );
    }

    public function testLinkRowsEmptyReadOnlyAndNew(): void
    {
        $empty = self::xpath(self::renderM4(['many' => self::manyLinks(['rows' => []])]));
        self::assertSame(0, $empty->query('//ul')->length);
        self::assertSame('No linked records', self::one($empty, '//div[@class="field"]/p[@class="static-value"]')->textContent);
        self::assertSame('Link a record', self::one($empty, '//a[@class="button button-strong"]')->textContent);
        self::assertSame(0, $empty->query('//form[contains(@id, "unlink")]')->length);

        $readOnly = self::xpath(self::renderM4(['many' => self::manyLinks(['unlink' => false, 'linkPath' => null])]));
        self::assertSame(2, $readOnly->query('//li[@class="link-row linked-row"]')->length);
        self::assertSame(0, $readOnly->query('//li//button | //a[contains(@class, "button-strong")] | //p[contains(@class, "help")]')->length);

        $new = self::xpath(self::renderM4(['many' => self::manyLinks(['rows' => [], 'unlink' => false, 'linkPath' => null, 'isNew' => true])]));
        self::assertSame(['You can add links once this record is saved.'], self::texts($new, '//div[@class="field"]/p[contains(@class, "help")]'));
        self::assertSame(0, $new->query('//ul | //p[@class="static-value"] | //div[@class="field"]//a')->length);
    }

    public function testLinkRowsEscapeHostileText(): void
    {
        $xpath = self::xpath(self::renderM4(['many' => self::manyLinks([
            'rows' => [['path' => '/projects/abcdefghijkl/tables/stuvwxyz2345/records/7', 'title' => self::HOSTILE, 'detail' => self::HOSTILE, 'target' => 7]],
            'item' => self::HOSTILE,
        ])]));
        self::assertSame(self::HOSTILE, self::one($xpath, '//div[@class="linked-main"]/a')->textContent);
        self::assertSame(self::HOSTILE, self::one($xpath, '//span[@class="link-detail"]')->textContent);
        self::assertSame('Unlink ' . self::HOSTILE, self::one($xpath, '//li/button')->getAttribute('aria-label'));
        self::assertSame('Link another ' . self::HOSTILE, self::one($xpath, '//a[@class="button button-strong"]')->textContent);
        self::assertSame('Unlinking does not delete the ' . self::HOSTILE . ' record.', self::one($xpath, '//div[@class="field"]/p[@class="help"]')->textContent);
    }

    /** @return array<string, mixed> */
    private static function fileRows(array $overrides = []): array
    {
        $files = self::RECORD . '/files/' . self::FIELD;

        return $overrides + [
            'key' => self::FIELD,
            'files' => [
                [
                    'name' => 'receipt-stand-mixer.pdf',
                    'type' => 'PDF',
                    'size' => '84 KB',
                    'openPath' => $files . '/4',
                    'replacePath' => $files . '/new',
                    'replaceQuery' => ['replace' => '4'],
                    'removePath' => $files . '/4/remove',
                    'image' => null,
                ],
                [
                    'name' => 'stand-mixer.png',
                    'type' => 'image',
                    'size' => '1.2 MB',
                    'openPath' => $files . '/5',
                    'replacePath' => $files . '/new',
                    'replaceQuery' => ['replace' => '5'],
                    'removePath' => $files . '/5/remove',
                    'image' => ['src' => '/projects/abcdefghijkl/files/5', 'alt' => 'stand-mixer.png'],
                ],
            ],
            'addPath' => $files . '/new',
            'imagesOnly' => false,
            'isNew' => false,
        ];
    }

    public function testFileRowsWithAPdfAndAnImage(): void
    {
        $files = self::RECORD . '/files/' . self::FIELD;
        $xpath = self::xpath(self::renderM4(['files' => self::fileRows()]));

        $rows = $xpath->query('//div[@class="field"]/ul[@class="file-list"]/li');
        self::assertSame(2, $rows->length);
        self::assertSame(['file-row', 'file-row file-row-image'], self::texts($xpath, '//ul[@class="file-list"]/li/@class'));
        self::assertSame(
            ['receipt-stand-mixer.pdf (PDF, 84 KB)', 'stand-mixer.png (image, 1.2 MB)'],
            self::texts($xpath, '//li/span[@class="file-name"]'),
        );
        self::assertSame(['(PDF, 84 KB)', '(image, 1.2 MB)'], self::texts($xpath, '//span[@class="file-name"]/span[@class="file-facts"]'));

        // One image, its src the serving route, its alt the name; it is not a
        // link of its own, so Open is the row's one link to the file page (P181).
        self::assertSame(1, $xpath->query('//img')->length);
        $img = self::one($xpath, '//li[@class="file-row file-row-image"]/img');
        self::assertSame(['alt' => 'stand-mixer.png', 'class' => 'file-image', 'src' => '/projects/abcdefghijkl/files/5'], self::attributes($img));
        self::assertSame($img, $xpath->query('//li[@class="file-row file-row-image"]/*')->item(0), 'The image comes first in its row.');
        self::assertSame(0, $xpath->query('//a//img')->length);
        self::assertSame([$files . '/5'], self::texts($xpath, '//li[@class="file-row file-row-image"]/a[@href="' . $files . '/5"]/@href'), 'One link to the file page per row.');

        self::assertSame(
            [
                ['Open', 'Open receipt-stand-mixer.pdf', $files . '/4', 'file-open'],
                ['Replace', 'Replace receipt-stand-mixer.pdf', $files . '/new?replace=4', 'button button-plain'],
                ['Remove', 'Remove receipt-stand-mixer.pdf', $files . '/4/remove', 'button button-plain'],
                ['Open', 'Open stand-mixer.png', $files . '/5', 'file-open'],
                ['Replace', 'Replace stand-mixer.png', $files . '/new?replace=5', 'button button-plain'],
                ['Remove', 'Remove stand-mixer.png', $files . '/5/remove', 'button button-plain'],
            ],
            array_map(
                static fn (DOMElement $a): array => [self::norm($a->textContent), $a->getAttribute('aria-label'), $a->getAttribute('href'), $a->getAttribute('class')],
                array_values(array_filter(iterator_to_array($xpath->query('//li/a[@aria-label]')), static fn ($n): bool => $n instanceof DOMElement)),
            ),
        );
        foreach ($xpath->query('//li/a[@aria-label]') as $a) {
            self::assertInstanceOf(DOMElement::class, $a);
            self::assertStringStartsWith(self::norm($a->textContent), $a->getAttribute('aria-label'));
        }

        $add = self::one($xpath, '//div[@class="field"]/div[@class="control-actions"]/a[@class="button button-strong"]');
        self::assertSame('Add a file', $add->textContent);
        self::assertSame($files . '/new', $add->getAttribute('href'));
        self::assertSame('f-' . self::FIELD, $add->getAttribute('aria-describedby'));
        self::assertSame(['Save your changes first: the buttons here leave this page.'], self::texts($xpath, '//div[@class="field"]/p[contains(@class, "help")]'));
    }

    public function testFileRowsImagesOnlyReadOnlyEmptyAndNew(): void
    {
        $images = self::xpath(self::renderM4(['files' => self::fileRows(['imagesOnly' => true])]));
        self::assertSame('Add an image', self::one($images, '//a[@class="button button-strong"]')->textContent);

        $readOnly = self::fileRows(['addPath' => null]);
        foreach ($readOnly['files'] as $n => $file) {
            $readOnly['files'][$n] = ['replacePath' => null, 'removePath' => null] + $file;
        }
        $xpath = self::xpath(self::renderM4(['files' => $readOnly]));
        self::assertSame(['Open', 'Open'], self::texts($xpath, '//li/a[@aria-label]'));
        self::assertSame(0, $xpath->query('//a[contains(@class, "button")] | //p[contains(@class, "help")]')->length);
        self::assertSame(1, $xpath->query('//img')->length, 'The image is shown to readers too.');

        $empty = self::xpath(self::renderM4(['files' => self::fileRows(['files' => []])]));
        self::assertSame('No files', self::one($empty, '//div[@class="field"]/p[@class="static-value"]')->textContent);
        self::assertSame(0, $empty->query('//ul | //img')->length);
        self::assertSame('Add a file', self::one($empty, '//a[@class="button button-strong"]')->textContent);

        $new = self::xpath(self::renderM4(['files' => self::fileRows(['files' => [], 'addPath' => null, 'isNew' => true])]));
        self::assertSame(['You can add files once this record is saved.'], self::texts($new, '//div[@class="field"]/p[contains(@class, "help")]'));
        self::assertSame(0, $new->query('//ul | //p[@class="static-value"] | //div[@class="field"]//a')->length);
    }

    public function testFileRowsEscapeHostileNames(): void
    {
        $files = self::fileRows();
        $files['files'][1]['name'] = self::HOSTILE . "\u{202E}gnp.exe";
        $files['files'][1]['image']['alt'] = self::HOSTILE;
        $files['files'][1]['size'] = self::HOSTILE;
        $xpath = self::xpath(self::renderM4(['files' => $files]));

        self::assertSame(self::HOSTILE, self::one($xpath, '//img')->getAttribute('alt'));
        self::assertSame('Open ' . self::HOSTILE . "\u{202E}gnp.exe", self::texts($xpath, '//li/a[@class="file-open"]/@aria-label')[1]);
        self::assertSame('Remove ' . self::HOSTILE . "\u{202E}gnp.exe", self::texts($xpath, '//li/a[@class="button button-plain"][last()]/@aria-label')[1]);
        self::assertSame('(image, ' . self::HOSTILE . ')', self::texts($xpath, '//span[@class="file-facts"]')[1]);
    }

    public function testFileRowsRefuseAnImageSourceThatIsNotAnApplicationPath(): void
    {
        foreach (['//example.org/x.png', 'data:image/png;base64,AAAA', 'javascript:alert(1)'] as $src) {
            $files = self::fileRows();
            $files['files'][1]['image']['src'] = $src;
            try {
                self::renderM4(['files' => $files]);
                self::fail("Accepted {$src}");
            } catch (\Throwable $e) {
                self::assertInstanceOf(InvalidArgumentException::class, self::rootCause($e), $src);
            }
        }
    }

    public function testLeaveNoteAlone(): void
    {
        $xpath = self::xpath(self::renderM4(['leave' => true]));
        self::assertSame('Save your changes first: the buttons here leave this page.', self::one($xpath, '//p[@class="help leave-note"]')->textContent);
    }
}
