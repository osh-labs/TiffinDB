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
        if (!copy(__DIR__ . '/fixtures/templates/pages/m2-components.php', $dir . '/pages/m2-components.php')) {
            throw new RuntimeException('Cannot copy the fixture.');
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
}
