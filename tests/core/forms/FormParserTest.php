<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Forms\Divider;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormFormat;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\LineBreak;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Placement;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\StartTags;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Support\RepoFiles;
use TiffinDB\Tests\Support\TempDir;

/**
 * T-FORM-2 (accepted documents) and T-FORM-4 (rejection corpus) for the
 * default-deny parser of M2 spec section 5.2, and T-SAN-6 (M5 spec section
 * 4.2): problem lines with LF, CR, and CR LF texts, the byte order mark,
 * and the start-tag table. All keys are synthetic.
 */
#[CoversClass(FormParser::class)]
#[CoversClass(FormRejected::class)]
#[CoversClass(StartTags::class)]
final class FormParserTest extends TestCase
{
    private const K1 = 'm4pz7k2qsw3n';
    private const K2 = 'w2qn6kz7tb4x';
    private const OPEN = '<div data-tiffin-form="1">';
    private const CLOSE = '</div>';

    private ?TempDir $temp = null;

    protected function tearDown(): void
    {
        $this->temp?->remove();
        $this->temp = null;
    }

    private static function parser(): FormParser
    {
        return new FormParser(FieldTypes::standard());
    }

    /** The example of `docs/formats/tiffin.md` section 12.2, read from the document. */
    public static function documentExample(): string
    {
        $doc = RepoFiles::read('docs/formats/tiffin.md');
        $start = (int) strpos($doc, '### 12.2 Example');
        $section = substr($doc, $start, (int) strpos($doc, '### 12.3') - $start);
        self::assertSame(1, preg_match('/```html\n(.*?)```/s', $section, $match));

        return $match[1];
    }

    // ------------------------------------------------------------ T-FORM-2

    public function testAcceptsTheDocumentExample(): void
    {
        $document = self::parser()->parse(self::documentExample());

        self::assertEquals(new FormDocument([
            new Section('details', 'Details', [
                new FieldSlot('m4pz7k2qsw3n'),
                new FieldSlot('w2qn6kz7tb4x'),
                new FieldSlot('r5vk3pn7qx2m'),
                new FieldSlot('b7xk2mq4nz3p'),
                new FieldSlot('t6mq2zk4wn7x', null, new Placement(null, null, 'full')),
            ]),
            new Section('files', 'Files', [new FieldSlot('p3zk7mq2xn4w')]),
            new Section('links', 'Warranties', [new FieldSlot('k2xq7vn4mz3t')]),
        ]), $document);
    }

    public function testAcceptsEveryElementAndAttribute(): void
    {
        $html = '<!-- A synthetic form using all of format 1. -->' . "\n"
            . self::OPEN . "\n"
            . '  <h2 data-tiffin-col="1" data-tiffin-row="1" data-tiffin-span="12">Title &amp; <em>more</em></h2>' . "\n"
            . '  <fieldset data-tiffin-section="my-role-2" data-tiffin-col="12" data-tiffin-row="50" data-tiffin-span="full">' . "\n"
            . '    <!-- comments are ignored -->' . "\n"
            . '    <legend>  Caf&#233; &lt;notes&gt; </legend>' . "\n"
            . '    <div data-tiffin-field="' . self::K1 . '" data-tiffin-label=" Shown name " data-tiffin-span="6"></div>' . "\n"
            . '    <h3>Sub</h3>' . "\n"
            . '    <p>One<br/>two <strong>bold <em>both</em></strong> <a href="https://example.com/a?b=1&amp;c=2">link <strong>text</strong></a></p>' . "\n"
            . '    <hr data-tiffin-row="3"/>' . "\n"
            . '    <img src="attachment:42" alt=" A photo " data-tiffin-col="2"/>' . "\n"
            . '  </fieldset>' . "\n"
            . '  <div data-tiffin-field="' . self::K2 . '"/>' . "\n"
            . '  <p></p>' . "\n"
            . '  <hr/>' . "\n"
            . '  <img src="attachment:9223372036854775807" alt=""></img>' . "\n"
            . '  <fieldset></fieldset>' . "\n"
            . self::CLOSE . "\n";

        $expected = new FormDocument([
            new Heading(2, [new TextRun('Title & '), new Emphasis([new TextRun('more')])], new Placement(1, 1, '12')),
            new Section('my-role-2', "Caf\u{E9} <notes>", [
                new FieldSlot(self::K1, 'Shown name', new Placement(null, null, '6')),
                new Heading(3, [new TextRun('Sub')]),
                new Paragraph([
                    new TextRun('One'),
                    new LineBreak(),
                    new TextRun('two '),
                    new Strong([new TextRun('bold '), new Emphasis([new TextRun('both')])]),
                    new TextRun(' '),
                    new Link('https://example.com/a?b=1&c=2', [new TextRun('link '), new Strong([new TextRun('text')])]),
                ]),
                new Divider(new Placement(null, 3)),
                new Image(42, 'A photo', new Placement(2)),
            ], new Placement(12, 50, 'full')),
            new FieldSlot(self::K2),
            new Paragraph([]),
            new Divider(),
            new Image(PHP_INT_MAX, ''),
            new Section(null, null, []),
        ]);
        self::assertEquals($expected, self::parser()->parse($html));
    }

    public function testTextRunsKeepTheirCharactersAndMerge(): void
    {
        $document = self::parser()->parse(self::OPEN . "<p>\n  a&#9;b\n  <!-- c -->c &#x1F600;\n</p>" . self::CLOSE);
        self::assertEquals(new FormDocument([new Paragraph([new TextRun("\n  a\tb\n  c \u{1F600}\n")])]), $document);
    }

    public function testRestoresTheLibxmlErrorSetting(): void
    {
        $before = libxml_use_internal_errors(false);
        try {
            try {
                self::parser()->parse(self::OPEN . '<p>&nbsp;</p>' . self::CLOSE);
                self::fail('The form was accepted.');
            } catch (FormRejected) {
                self::assertFalse(libxml_use_internal_errors(false));
            }
            self::parser()->parse(self::OPEN . self::CLOSE);
            self::assertFalse(libxml_use_internal_errors(false));
            self::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($before);
        }
    }

    // ------------------------------------------------------------ T-FORM-4

    private static function wrap(string $inner): string
    {
        return self::OPEN . $inner . self::CLOSE;
    }

    /** @return iterable<string, array{string, string}> */
    public static function hostile(): iterable
    {
        $k1 = self::K1;
        $slot = '<div data-tiffin-field="' . $k1 . '"></div>';

        yield 'billion laughs' => ['<!DOCTYPE div [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]>' . self::wrap('<p>&c;</p>'), FormRejected::DECLARATION];
        yield 'external entity' => ['<!DOCTYPE div [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . self::wrap('<p>&x;</p>'), FormRejected::DECLARATION];
        yield 'external DTD' => ['<!DOCTYPE div SYSTEM "http://192.0.2.1/form.dtd">' . self::wrap(''), FormRejected::DECLARATION];
        yield 'doctype in lower case' => ['<!doctype html>' . self::wrap(''), FormRejected::DECLARATION];
        yield 'xml declaration' => ['<?xml version="1.0" encoding="UTF-8"?>' . self::wrap(''), FormRejected::PROCESSING_INSTRUCTION];
        yield 'processing instruction' => [self::wrap('<?php echo 1; ?>'), FormRejected::PROCESSING_INSTRUCTION];
        yield 'stylesheet instruction' => ['<?xml-stylesheet href="http://192.0.2.1/x.xsl"?>' . self::wrap(''), FormRejected::PROCESSING_INSTRUCTION];
        yield 'cdata' => [self::wrap('<p><![CDATA[<script>alert(1)</script>]]></p>'), FormRejected::DECLARATION];
        yield 'declaration inside a comment' => [self::wrap('<!-- <!ENTITY x "y"> -->'), FormRejected::DECLARATION];
        yield 'undefined entity' => [self::wrap('<p>&nbsp;</p>'), FormRejected::MALFORMED];
        yield 'script' => [self::wrap('<script>alert(1)</script>'), FormRejected::ELEMENT];
        yield 'script in a paragraph' => [self::wrap('<p><script>alert(1)</script></p>'), FormRejected::ELEMENT];
        yield 'style element' => [self::wrap('<style>body{}</style>'), FormRejected::ELEMENT];
        yield 'iframe' => [self::wrap('<iframe src="https://example.com/"></iframe>'), FormRejected::ELEMENT];
        yield 'form' => [self::wrap('<form action="https://example.com/"></form>'), FormRejected::ELEMENT];
        yield 'input' => [self::wrap('<input name="x"/>'), FormRejected::ELEMENT];
        yield 'svg' => [self::wrap('<svg><script>alert(1)</script></svg>'), FormRejected::ELEMENT];
        yield 'span' => [self::wrap('<p><span>x</span></p>'), FormRejected::ELEMENT];
        yield 'upper-case element' => [self::wrap('<P>x</P>'), FormRejected::ELEMENT];
        yield 'mixed-case element' => [self::wrap('<Fieldset></Fieldset>'), FormRejected::ELEMENT];
        yield 'prefixed element' => [self::wrap('<h:p xmlns:h="http://www.w3.org/1999/xhtml">x</h:p>'), FormRejected::ELEMENT];
        yield 'on attribute' => [self::wrap('<p onclick="alert(1)">x</p>'), FormRejected::ATTRIBUTE];
        yield 'onerror on img' => [self::wrap('<img src="attachment:1" alt="" onerror="alert(1)"/>'), FormRejected::ATTRIBUTE];
        yield 'style attribute' => [self::wrap('<p style="color:red">x</p>'), FormRejected::ATTRIBUTE];
        yield 'class' => [self::wrap('<p class="x">x</p>'), FormRejected::ATTRIBUTE];
        yield 'id' => [self::wrap('<p id="x">x</p>'), FormRejected::ATTRIBUTE];
        yield 'xmlns' => [self::wrap('<p xmlns="http://www.w3.org/1999/xhtml">x</p>'), FormRejected::ATTRIBUTE];
        yield 'xmlns prefix declaration' => [self::wrap('<p xmlns:x="urn:example">x</p>'), FormRejected::ATTRIBUTE];
        yield 'prefixed attribute' => [self::wrap('<div data-tiffin-field="' . $k1 . '" xmlns:x="urn:example" x:label="a"></div>'), FormRejected::ATTRIBUTE];
        yield 'xml:lang' => [self::wrap('<p xml:lang="en">x</p>'), FormRejected::ATTRIBUTE];
        yield 'upper-case attribute' => [self::wrap('<div DATA-TIFFIN-FIELD="' . $k1 . '"></div>'), FormRejected::ATTRIBUTE];
        yield 'unknown data attribute' => [self::wrap('<div data-tiffin-field="' . $k1 . '" data-tiffin-width="3"></div>'), FormRejected::ATTRIBUTE];
        yield 'placement on a legend' => [self::wrap('<fieldset><legend data-tiffin-span="full">x</legend></fieldset>'), FormRejected::ATTRIBUTE];
        yield 'href on strong' => [self::wrap('<p><strong href="https://example.com/">x</strong></p>'), FormRejected::ATTRIBUTE];
        yield 'field slot without key' => [self::wrap('<div></div>'), FormRejected::ATTRIBUTE];
        yield 'img without alt' => [self::wrap('<img src="attachment:1"/>'), FormRejected::ATTRIBUTE];
        yield 'link without href' => [self::wrap('<p><a>x</a></p>'), FormRejected::ATTRIBUTE];
        yield 'javascript href' => [self::wrap('<p><a href="javascript:alert(1)">x</a></p>'), FormRejected::HREF];
        yield 'javascript href with entities' => [self::wrap('<p><a href="&#106;avascript:alert(1)">x</a></p>'), FormRejected::HREF];
        yield 'data href' => [self::wrap('<p><a href="data:text/html,x">x</a></p>'), FormRejected::HREF];
        yield 'href with tab' => [self::wrap('<p><a href="https://exa&#9;mple.com/">x</a></p>'), FormRejected::HREF];
        yield 'href ending in a tab' => [self::wrap('<p><a href="https://example.com/&#9;">x</a></p>'), FormRejected::HREF];
        // The url type trims; FormRules::isHref in FormParser::values refuses at the attribute, before the
        // script inside the link is reached (without that check the script would be reported instead).
        yield 'href with leading space, at the attribute' => [self::wrap('<p><a href=" https://example.com/"><script/></a></p>'), FormRejected::HREF];
        yield 'href with trailing space, at the attribute' => [self::wrap('<p><a href="https://example.com/ "><script/></a></p>'), FormRejected::HREF];
        yield 'href with leading line feed, at the attribute' => [self::wrap('<p><a href="&#10;https://example.com/"><script/></a></p>'), FormRejected::HREF];
        yield 'href with trailing line feed, at the attribute' => [self::wrap('<p><a href="https://example.com/&#10;"><script/></a></p>'), FormRejected::HREF];
        yield 'href with trailing space' => [self::wrap('<p><a href="https://example.com/ ">x</a></p>'), FormRejected::HREF];
        yield 'href with line feed' => [self::wrap('<p><a href="https://example.com/&#10;">x</a></p>'), FormRejected::HREF];
        yield 'href with leading space' => [self::wrap('<p><a href=" https://example.com/">x</a></p>'), FormRejected::HREF];
        yield 'scheme-relative href' => [self::wrap('<p><a href="//example.com/">x</a></p>'), FormRejected::HREF];
        yield 'empty href' => [self::wrap('<p><a href="">x</a></p>'), FormRejected::HREF];
        yield 'outside image' => [self::wrap('<img src="https://example.com/x.png" alt=""/>'), FormRejected::SRC];
        yield 'attachment 0' => [self::wrap('<img src="attachment:0" alt=""/>'), FormRejected::SRC];
        yield 'attachment with leading zero' => [self::wrap('<img src="attachment:07" alt=""/>'), FormRejected::SRC];
        yield 'attachment beyond an integer' => [self::wrap('<img src="attachment:9223372036854775808" alt=""/>'), FormRejected::SRC];
        yield 'attachment 20 digits' => [self::wrap('<img src="attachment:12345678901234567890" alt=""/>'), FormRejected::SRC];
        yield 'attachment upper case' => [self::wrap('<img src="ATTACHMENT:1" alt=""/>'), FormRejected::SRC];
        yield 'bad field key' => [self::wrap('<div data-tiffin-field="M4PZ7K2QSW3N"></div>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'label too long' => [self::wrap('<div data-tiffin-field="' . $k1 . '" data-tiffin-label="' . str_repeat('a', 61) . '"></div>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'label blank' => [self::wrap('<div data-tiffin-field="' . $k1 . '" data-tiffin-label="   "></div>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'label with control' => [self::wrap('<div data-tiffin-field="' . $k1 . '" data-tiffin-label="a&#x202E;b"></div>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'alt too long' => [self::wrap('<img src="attachment:1" alt="' . str_repeat('a', 201) . '"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'bad role' => [self::wrap('<fieldset data-tiffin-section="Details"></fieldset>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'column 13' => [self::wrap('<hr data-tiffin-col="13"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'column with leading zero' => [self::wrap('<hr data-tiffin-col="01"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'column with sign' => [self::wrap('<hr data-tiffin-col="+1"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'row 51' => [self::wrap('<hr data-tiffin-row="51"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'span half' => [self::wrap('<hr data-tiffin-span="half"/>'), FormRejected::ATTRIBUTE_VALUE];
        yield 'duplicate field slot' => [self::wrap($slot . '<fieldset>' . $slot . '</fieldset>'), FormRejected::DUPLICATE_FIELD];
        yield 'nested fieldset' => [self::wrap('<fieldset><fieldset></fieldset></fieldset>'), FormRejected::MISPLACED];
        yield 'legend in the root' => [self::wrap('<legend>x</legend>'), FormRejected::MISPLACED];
        yield 'legend after a block' => [self::wrap('<fieldset><hr/><legend>x</legend></fieldset>'), FormRejected::MISPLACED];
        yield 'two legends' => [self::wrap('<fieldset><legend>x</legend><legend>y</legend></fieldset>'), FormRejected::MISPLACED];
        yield 'block in a paragraph' => [self::wrap('<p><hr/></p>'), FormRejected::MISPLACED];
        yield 'inline in the root' => [self::wrap('<strong>x</strong>'), FormRejected::MISPLACED];
        yield 'link in a link' => [self::wrap('<p><a href="https://example.com/"><em><a href="https://example.org/">x</a></em></a></p>'), FormRejected::MISPLACED];
        yield 'element in a legend' => [self::wrap('<fieldset><legend><strong>x</strong></legend></fieldset>'), FormRejected::CONTENT];
        yield 'text in a field slot' => [self::wrap('<div data-tiffin-field="' . $k1 . '">x</div>'), FormRejected::CONTENT];
        yield 'white space in a field slot' => [self::wrap('<div data-tiffin-field="' . $k1 . '"> </div>'), FormRejected::CONTENT];
        yield 'element in hr' => [self::wrap('<hr><br/></hr>'), FormRejected::CONTENT];
        yield 'text directly in a fieldset' => [self::wrap('<fieldset>loose text</fieldset>'), FormRejected::TEXT];
        yield 'text directly in the root' => [self::wrap('loose text'), FormRejected::TEXT];
        yield 'empty legend' => [self::wrap('<fieldset><legend>  </legend></fieldset>'), FormRejected::TEXT_VALUE];
        yield 'legend too long' => [self::wrap('<fieldset><legend>' . str_repeat('x', 101) . '</legend></fieldset>'), FormRejected::TEXT_VALUE];
        yield 'carriage return in text' => [self::wrap('<p>a&#13;b</p>'), FormRejected::TEXT_VALUE];
        yield 'bidi override in text' => [self::wrap("<p>a\u{202E}b</p>"), FormRejected::TEXT_VALUE];
        yield 'control reference' => [self::wrap('<p>&#1;</p>'), FormRejected::MALFORMED];
        yield 'more than 8 levels' => [self::wrap('<fieldset><p><strong><em><strong><em><strong><em>x</em></strong></em></strong></em></strong></p></fieldset>'), FormRejected::TOO_DEEP];
        yield 'more than 5,000 elements' => [self::wrap(str_repeat('<hr/>', 5000)), FormRejected::TOO_MANY_ELEMENTS];
        yield '262,145 bytes' => [self::wrap('<p>' . str_repeat('a', 262145 - strlen(self::wrap('<p></p>'))) . '</p>'), FormRejected::TOO_LARGE];
        yield 'invalid utf-8' => [self::wrap("<p>\xC3\x28</p>"), FormRejected::ENCODING];
        yield 'overlong utf-8' => [self::wrap("<p>\xC0\xBC</p>"), FormRejected::ENCODING];
        yield 'NUL' => [self::wrap("<p>a\0b</p>"), FormRejected::NUL];
        yield 'root with a second attribute' => ['<div data-tiffin-form="1" class="x"></div>', FormRejected::ROOT];
        yield 'root with xmlns' => ['<div xmlns="http://www.w3.org/1999/xhtml" data-tiffin-form="1"></div>', FormRejected::ROOT];
        yield 'root without attribute' => ['<div></div>', FormRejected::ROOT];
        yield 'root not a div' => ['<fieldset data-tiffin-form="1"></fieldset>', FormRejected::ROOT];
        yield 'root is a field slot' => ['<div data-tiffin-field="' . $k1 . '"></div>', FormRejected::ROOT];
        yield 'two roots' => [self::wrap('') . self::wrap(''), FormRejected::MALFORMED];
        yield 'wrong format number' => ['<div data-tiffin-form="2"></div>', FormRejected::FORMAT_VERSION];
        yield 'format number with space' => ['<div data-tiffin-form=" 1"></div>', FormRejected::FORMAT_VERSION];
        yield 'text before the root' => ['x' . self::wrap(''), FormRejected::MALFORMED];
        yield 'unclosed element' => [self::OPEN . '<p>x', FormRejected::MALFORMED];
        yield 'empty text' => ['', FormRejected::ROOT];
        yield 'white space only' => ["  \n", FormRejected::MALFORMED];
        yield 'html void element' => [self::wrap('<p>a<br>b</p>'), FormRejected::MALFORMED];
        yield 'unquoted attribute' => ['<div data-tiffin-form=1></div>', FormRejected::MALFORMED];
        yield 'duplicate attribute' => ['<div data-tiffin-form="1" data-tiffin-form="1"></div>', FormRejected::MALFORMED];
    }

    #[DataProvider('hostile')]
    public function testRejects(string $html, string $reason): void
    {
        try {
            self::parser()->parse($html);
            self::fail('The form was accepted.');
        } catch (FormRejected $rejected) {
            self::assertSame($reason, $rejected->reason);
            self::assertStringNotContainsString('script', $rejected->getMessage());
            self::assertSame('The form does not follow form format 1.', $rejected->getMessage());
        }
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $parser = self::parser();
        self::assertCount(4999, $parser->parse(self::wrap(str_repeat('<hr/>', 4999)))->blocks);
        $body = str_repeat('a', 262144 - strlen(self::wrap('<p></p>')));
        self::assertCount(1, $parser->parse(self::wrap('<p>' . $body . '</p>'))->blocks);
        $eight = self::wrap('<fieldset><p><strong><em><strong><em><strong>x</strong></em></strong></em></strong></p></fieldset>');
        self::assertCount(1, $parser->parse($eight)->blocks);
    }

    /**
     * ADR 0014 decision 4 and M2 spec section 14 item 4: FormParser's own
     * reader settings neither fetch nor expand an external entity even when
     * a DOCTYPE gets past the pre-scan. The private walk() (the reader
     * stage, after the pre-scan) is called directly so that only those
     * settings stand between the document and the marker file, whose
     * content must never appear in a result or an error.
     */
    public function testReaderStageAloneDoesNotExpandEntities(): void
    {
        $this->temp = TempDir::create();
        $secret = $this->temp->path('marker.txt');
        file_put_contents($secret, 'SYNTHETIC-MARKER-0001');
        $documents = [
            'external entity' => '<!DOCTYPE div [<!ENTITY x SYSTEM "file://' . $secret . '">]>' . self::wrap('<p>&x;</p>'),
            'internal entity' => '<!DOCTYPE div [<!ENTITY y "<script>alert(1)</script>">]>' . self::wrap('<p>&y;</p>'),
            'billion laughs' => '<!DOCTYPE div [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
                . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]>' . self::wrap('<p>&c;</p>'),
            'external DTD' => '<!DOCTYPE div SYSTEM "file://' . $secret . '">' . self::wrap(''),
        ];
        $parser = self::parser();
        $walk = new \ReflectionMethod(FormParser::class, 'walk');
        foreach ($documents as $name => $xml) {
            $previous = libxml_use_internal_errors(true);
            try {
                $walk->invoke($parser, $xml);
                self::fail($name . ': the reader stage accepted a document type.');
            } catch (FormRejected $rejected) {
                self::assertContains($rejected->reason, [FormRejected::NODE_TYPE, FormRejected::MALFORMED], $name);
                self::assertStringNotContainsString('SYNTHETIC-MARKER', $rejected->getMessage(), $name);
            } finally {
                foreach (libxml_get_errors() as $error) {
                    self::assertStringNotContainsString('SYNTHETIC-MARKER', $error->message, $name);
                }
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            // Through parse(), the pre-scan refuses it first.
            try {
                $parser->parse($xml);
                self::fail($name . ': the form was accepted.');
            } catch (FormRejected $rejected) {
                self::assertSame(FormRejected::DECLARATION, $rejected->reason, $name);
            }
        }
    }

    // ------------------------------------------------------------ T-SAN-6

    /**
     * One text per reason group, written with $break between lines; the
     * problem sits on line 4.
     *
     * @return array<string, array{string, string}> group => [text, reason]
     */
    private static function linedTexts(string $break): array
    {
        $head = self::OPEN . $break . '<p>one</p>' . $break . '<!-- a < b -->' . $break;
        $tail = $break . self::CLOSE . $break;

        return [
            'encoding' => [$head . "<p>\xC3\x28</p>" . $tail, FormRejected::ENCODING],
            'nul' => [$head . "<p>a\0</p>" . $tail, FormRejected::NUL],
            'declaration' => [$head . '<p><![CDATA[x]]></p>' . $tail, FormRejected::DECLARATION],
            'instruction' => [$head . '<?x y?>' . $tail, FormRejected::PROCESSING_INSTRUCTION],
            'malformed by libxml' => [$head . '<p>a</b>' . $tail, FormRejected::MALFORMED],
            'duplicate attribute' => [$head . '<p data-tiffin-span="6" data-tiffin-span="4">x</p>' . $tail, FormRejected::MALFORMED],
            'element' => [$head . '<script>x</script>' . $tail, FormRejected::ELEMENT],
            'misplaced' => [$head . '<legend>x</legend>' . $tail, FormRejected::MISPLACED],
            'attribute' => [$head . '<p class="x">x</p>' . $tail, FormRejected::ATTRIBUTE],
            'attribute value' => [$head . '<p data-tiffin-span="0">x</p>' . $tail, FormRejected::ATTRIBUTE_VALUE],
            'href' => [$head . '<p><a href="javascript:x">x</a></p>' . $tail, FormRejected::HREF],
            'src' => [$head . '<img src="attachment:0" alt=""/>' . $tail, FormRejected::SRC],
            'content' => [$head . '<hr><br/></hr>' . $tail, FormRejected::CONTENT],
            'duplicate field' => [$head . self::slot() . self::slot() . $tail, FormRejected::DUPLICATE_FIELD],
            'too deep' => [$head . '<fieldset><p><strong><em><strong><em><strong><em>x</em></strong></em></strong></em></strong></p></fieldset>' . $tail, FormRejected::TOO_DEEP],
            'text near its element' => [$head . '<p>' . $break . "a\u{202E}b" . $break . '</p>' . $tail, FormRejected::TEXT_VALUE],
            'text in a section' => [$head . '<fieldset>' . $break . 'loose' . $break . '</fieldset>' . $tail, FormRejected::TEXT],
            'legend' => [$head . '<fieldset><legend>' . $break . $break . '</legend></fieldset>' . $tail, FormRejected::TEXT_VALUE],
        ];
    }

    private static function slot(): string
    {
        return '<div data-tiffin-field="' . self::K1 . '"></div>';
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function linedCases(): iterable
    {
        foreach (['LF' => "\n", 'CR' => "\r", 'CR LF' => "\r\n"] as $name => $break) {
            foreach (self::linedTexts($break) as $group => [$text, $reason]) {
                yield $group . ', ' . $name => [$text, $reason, $break];
            }
        }
    }

    #[DataProvider('linedCases')]
    public function testProblemsAreReportedOnTheirLine(string $text, string $reason, string $break): void
    {
        foreach (['' => 0, "\xEF\xBB\xBF" => 0] as $prefix => $unused) {
            try {
                self::parser()->parse($prefix . $text);
                self::fail('The form was accepted.');
            } catch (FormRejected $rejected) {
                self::assertSame($reason, $rejected->reason);
                // The byte order mark is removed and does not shift lines.
                self::assertSame(4, $rejected->formLine, $prefix === '' ? 'plain' : 'with a byte order mark');
                self::assertSame(4, $rejected->params()['line']);
            }
        }
    }

    public function testLinesThatAreUnknown(): void
    {
        foreach (['' => FormRejected::ROOT, "\xEF\xBB\xBF" => FormRejected::ROOT] as $text => $reason) {
            $rejected = self::rejectionOf($text);
            self::assertSame([$reason, null], [$rejected->reason, $rejected->formLine]);
        }
        $large = self::wrap("\n\n<p>" . str_repeat('a', FormFormat::MAX_BYTES) . '</p>');
        self::assertSame([FormRejected::TOO_LARGE, null], [self::rejectionOf($large)->reason, self::rejectionOf($large)->formLine]);
        // Text after the root is reported at the root's start tag by the walk; libxml refuses it first.
        $after = self::rejectionOf("\n\n" . self::wrap('') . "\nx");
        self::assertSame([FormRejected::MALFORMED, 4], [$after->reason, $after->formLine]);
    }

    public function testElementAndAttributeNamesAreGiven(): void
    {
        $element = self::rejectionOf(self::wrap('<p><span>x</span></p>'));
        self::assertSame(['span', null, 'problem.form.element'], [$element->element, $element->attribute, $element->messageKey()]);
        $attribute = self::rejectionOf(self::wrap('<p class="x">x</p>'));
        self::assertSame(['p', 'class', 'problem.form.attribute'], [$attribute->element, $attribute->attribute, $attribute->messageKey()]);
        self::assertSame(['line' => 1, 'element' => 'p', 'attribute' => 'class'], $attribute->params());
        $missing = self::rejectionOf(self::wrap('<img alt=""/>'));
        self::assertSame(['img', 'src'], [$missing->element, $missing->attribute]);
        $value = self::rejectionOf(self::wrap('<hr data-tiffin-row="0"/>'));
        self::assertSame(['hr', 'data-tiffin-row', 'problem.form.attribute_value'], [$value->element, $value->attribute, $value->messageKey()]);
        $long = self::rejectionOf(self::wrap('<p ' . str_repeat('z', 45) . '="1">x</p>'));
        self::assertSame(['p', null, 'problem.form.attribute_unnamed'], [$long->element, $long->attribute, $long->messageKey()]);
        $href = self::rejectionOf(self::wrap('<p><a href="vbscript:x">x</a></p>'));
        self::assertSame([FormRejected::HREF, 'a', 'href', 'problem.form.href'], [$href->reason, $href->element, $href->attribute, $href->messageKey()]);
    }

    private static function rejectionOf(string $text): FormRejected
    {
        try {
            self::parser()->parse($text);
        } catch (FormRejected $rejected) {
            return $rejected;
        }
        self::fail('The form was accepted.');
    }

    public function testStartTagOffsetsSkipCommentsAndEndTags(): void
    {
        $text = '<div a="1"><!-- <p> < --><p>x</p><br/><!----><hr/></div>';
        self::assertSame([0, 25, 33, 45], StartTags::offsets($text));
        self::assertSame([0], StartTags::offsets('<div><!-- unterminated <p>'));
        self::assertSame([], StartTags::offsets('no tags'));
        self::assertSame([0, 9], StartTags::offsets('<a></a>  <b/>'));
        self::assertSame([3], StartTags::offsets('</a<b>'));
    }

    public function testLineAtCountsEachBreakOnce(): void
    {
        self::assertSame(1, StartTags::lineAt("abc", 2));
        self::assertSame(2, StartTags::lineAt("a\nb", 2));
        self::assertSame(2, StartTags::lineAt("a\rb", 2));
        self::assertSame(2, StartTags::lineAt("a\r\nb", 3));
        self::assertSame(5, StartTags::lineAt("a\r\n\r\n\n\rb", 7));
        self::assertSame(1, StartTags::lineAt("a\nb", 0));
        // A CR LF split by the offset counts the CR alone.
        self::assertSame(2, StartTags::lineAt("a\r\nb", 2));
    }

    public function testLinesAreCountedFarIntoALongForm(): void
    {
        $text = self::OPEN . "\n" . str_repeat("<hr/>\n", 4997) . "<hr title=\"x\"/>\n" . self::CLOSE;
        $rejected = self::rejectionOf($text);
        self::assertSame([FormRejected::ATTRIBUTE, 4999], [$rejected->reason, $rejected->formLine]);
    }
}
