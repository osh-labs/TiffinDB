<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Text;
use TiffinDB\Core\Forms\Block;
use TiffinDB\Core\Forms\Emphasis;
use TiffinDB\Core\Forms\FieldSlot;
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormFormat;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormRules;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\Heading;
use TiffinDB\Core\Forms\Image;
use TiffinDB\Core\Forms\Inline;
use TiffinDB\Core\Forms\Link;
use TiffinDB\Core\Forms\Paragraph;
use TiffinDB\Core\Forms\Section;
use TiffinDB\Core\Forms\Strong;
use TiffinDB\Core\Forms\TextRun;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Support\FormFixture;
use TiffinDB\Tests\Support\SeededRandom;

/**
 * T-SAN-3 (M5 spec section 11.1): 2,000 seeded mutations of serialized
 * FormFixture documents. Each mutated text is refused with FormRejected
 * (nothing else may be thrown), or accepted with the fixed-point property
 * holding, every link address passing the url rule and starting with
 * http:// or https://, and no text, label, legend, or alt holding a
 * character the text rules refuse.
 */
#[CoversClass(FormParser::class)]
#[CoversClass(FormRules::class)]
final class FormMutationTest extends TestCase
{
    private const SEED = '20261124';
    private const MUTATIONS = 2000;

    private const ELEMENTS = [
        '<script>alert(1)</script>', '<svg onload="alert(1)"/>', '<iframe src="https://example.com/"></iframe>',
        '<style>p{}</style>', '<img src="x" onerror="alert(1)"/>', '<a href="javascript:alert(1)">x</a>',
        '<object data="x"></object>', '<math></math>', '<x:script xmlns:x="http://www.w3.org/1999/xhtml"/>',
        '<input/>', '<form></form>', '<template></template>', '<base href="https://example.com/"/>', '<span>x</span>',
        '<fieldset></fieldset>', '<legend>x</legend>', '<p>x</p>', '<hr/>', '<br/>', '<strong>x</strong>',
    ];
    private const ATTRIBUTES = [
        ' onclick="alert(1)"', ' ONERROR="x"', ' style="color:red"', ' class="x"', ' id="x"', ' href="javascript:alert(1)"',
        ' xmlns="http://www.w3.org/1999/xhtml"', ' xmlns:x="urn:x"', ' xml:base="https://example.com/"', ' src="attachment:1"',
        ' data-tiffin-span="full"', ' data-tiffin-label="x"', ' target="_blank"', ' srcset="x"', ' hidden=""',
    ];
    private const REFERENCES = [
        '&#x202E;', '&#0;', '&#xFDD0;', '&#x1F;', '&#106;', '&lt;', '&#x3C;script&#x3E;', '&#xD;', '&#x85;', '&#x200B;',
        '&#xFEFF;', '&#x10FFFF;', '&#x9;', '&#xA;', '&nbsp;', '&amp;', '&#x26;', '&#x22;', '&quot;', '&#x2028;',
    ];
    private const SCHEMES = [
        'javascript:', 'JaVaScRiPt:', 'data:text/html,', '&#106;avascript:', 'java&#x09;script:', 'vbscript:', '//',
        'HTTPS://', 'https:', 'http:/', 'file://', ' https://', 'https://user@', 'ftp://', '&#x68;ttps://',
    ];

    private SeededRandom $random;

    private function int(int $max): int
    {
        $value = unpack('N', $this->random->bytes(4));

        return ($value === false ? 0 : (int) $value[1]) % ($max + 1);
    }

    /** @param list<string> $list */
    private function pick(array $list): string
    {
        return $list[$this->int(count($list) - 1)];
    }

    /** @return list<array{int, int}> offset and length of every tag */
    private static function tags(string $text): array
    {
        preg_match_all('~</?[A-Za-z][^<>]*>~', $text, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $m): array => [$m[1], strlen($m[0])], $matches[0]);
    }

    /** @return array{string, string} the mutation's name and the mutated text */
    private function mutate(string $text): array
    {
        $tags = self::tags($text);
        $length = strlen($text);
        $op = $this->int(8);
        switch ($op) {
            case 0:
                $at = $tags[$this->int(count($tags) - 1)];
                $where = $this->int(1) === 0 ? $at[0] : $at[0] + $at[1];

                return ['element', substr_replace($text, $this->pick(self::ELEMENTS), $where, 0)];
            case 1:
                $starts = array_values(array_filter($tags, static fn (array $t): bool => $text[$t[0] + 1] !== '/'));
                $at = $starts[$this->int(count($starts) - 1)];
                $end = $at[0] + $at[1] - (str_ends_with(substr($text, $at[0], $at[1]), '/>') ? 2 : 1);

                return ['attribute', substr_replace($text, $this->pick(self::ATTRIBUTES), $end, 0)];
            case 2:
                return ['reference', substr_replace($text, $this->pick(self::REFERENCES), $this->int($length), 0)];
            case 3:
                $count = preg_match_all('~https?://~i', $text, $matches, PREG_OFFSET_CAPTURE);
                if ($count === 0 || $count === false) {
                    return ['scheme', str_replace('<p>', '<p><a href="' . $this->pick(self::SCHEMES) . 'example.com/">x</a>', $text)];
                }
                $m = $matches[0][$this->int($count - 1)];

                return ['scheme', substr_replace($text, $this->pick(self::SCHEMES), $m[1], strlen($m[0]))];
            case 4:
                return ['byte', substr_replace($text, chr($this->int(255)), $this->int($length), 0)];
            case 5:
            case 6:
                $duplicate = $op === 6;
                if ($this->int(1) === 0) {
                    $at = $tags[$this->int(count($tags) - 1)];
                    $piece = substr($text, $at[0], $at[1]);

                    return [$duplicate ? 'duplicate tag' : 'delete tag', substr_replace($text, $duplicate ? $piece . $piece : '', $at[0], $at[1])];
                }
                $char = $this->pick(['<', '>', '"']);
                $count = substr_count($text, $char);
                $offset = -1;
                for ($i = 0, $n = $this->int($count - 1); $i <= $n; $i++) {
                    $offset = (int) strpos($text, $char, $offset + 1);
                }

                return [($duplicate ? 'duplicate ' : 'delete ') . $char, substr_replace($text, $duplicate ? $char . $char : '', $offset, 1)];
            case 7:
                $a = $this->int($length - 1);
                $b = $this->int($length - 1);
                $swapped = $text;
                $swapped[$a] = $text[$b];
                $swapped[$b] = $text[$a];

                return ['swap', $swapped];
            default:
                preg_match_all('~(?<=<|</| )[a-z][a-z0-9:-]*~', $text, $matches, PREG_OFFSET_CAPTURE);
                $m = $matches[0][$this->int(count($matches[0]) - 1)];
                $name = $m[0];
                $changed = $this->int(1) === 0 ? strtoupper($name) : ucfirst($name);

                return ['case', substr_replace($text, $changed, $m[1], strlen($name))];
        }
    }

    public function testMutationsAreRefusedOrSafe(): void
    {
        $this->random = new SeededRandom(self::SEED);
        $fixture = new FormFixture();
        $parser = new FormParser(FieldTypes::standard());
        $serializer = new FormSerializer();
        $outcomes = [];
        $documents = [];
        for ($i = 0; $i < 50; $i++) {
            $documents[] = $serializer->serialize($fixture->document());
        }

        for ($n = 0; $n < self::MUTATIONS; $n++) {
            [$name, $text] = $this->mutate($documents[$n % count($documents)]);
            try {
                $document = $parser->parse($text);
            } catch (FormRejected $rejected) {
                self::assertContains($rejected->reason, FormRejected::REASONS);
                $outcomes[$name]['refused'] = ($outcomes[$name]['refused'] ?? 0) + 1;

                continue;
            }
            $outcomes[$name]['accepted'] = ($outcomes[$name]['accepted'] ?? 0) + 1;
            $message = 'Mutation ' . $n . ' (' . $name . ')';
            $this->assertSafe($document, $message);
            $canonical = $serializer->serialize($document);
            if (strlen($canonical) <= FormFormat::MAX_BYTES) {
                self::assertSame($canonical, $serializer->serialize($parser->parse($canonical)), $message);
            }
        }

        // Every kind of mutation ran, and the set holds both outcomes.
        foreach (['element', 'attribute', 'reference', 'scheme', 'byte', 'delete tag', 'duplicate tag', 'swap', 'case'] as $kind) {
            self::assertArrayHasKey($kind, $outcomes, $kind);
        }
        $refused = array_sum(array_map(static fn (array $o): int => $o['refused'] ?? 0, $outcomes));
        self::assertGreaterThan(self::MUTATIONS / 2, $refused);
        self::assertLessThan(self::MUTATIONS, $refused);
        // Hostile insertions of elements are never accepted unless the element is one of format 1.
        self::assertGreaterThan(0, $outcomes['element']['refused'] ?? 0);
    }

    private function assertSafe(FormDocument $document, string $message): void
    {
        foreach ($document->blocks as $block) {
            $this->assertSafeBlock($block, $message);
        }
    }

    private function assertSafeBlock(Block $block, string $message): void
    {
        if ($block instanceof Section) {
            if ($block->legend !== null) {
                self::assertTrue(FormRules::isPlainText($block->legend, 1, FormFormat::MAX_LEGEND), $message);
            }
            foreach ($block->children as $child) {
                self::assertNotInstanceOf(Section::class, $child, $message);
                $this->assertSafeBlock($child, $message);
            }
        } elseif ($block instanceof FieldSlot) {
            if ($block->label !== null) {
                self::assertTrue(FormRules::isPlainText($block->label, 1, FormFormat::MAX_LABEL), $message);
            }
        } elseif ($block instanceof Image) {
            self::assertGreaterThan(0, $block->attachmentId, $message);
            self::assertTrue(FormRules::isPlainText($block->alt, 0, FormFormat::MAX_ALT), $message);
        } elseif ($block instanceof Heading || $block instanceof Paragraph) {
            $this->assertSafeInlines($block->children, false, $message);
        }
    }

    /** @param list<Inline> $inlines */
    private function assertSafeInlines(array $inlines, bool $inLink, string $message): void
    {
        foreach ($inlines as $inline) {
            if ($inline instanceof TextRun) {
                self::assertFalse(Text::hasControl($inline->text, true, true), $message);
                self::assertTrue(FormRules::isRunText($inline->text), $message);
            } elseif ($inline instanceof Link) {
                self::assertFalse($inLink, $message);
                self::assertTrue(FormRules::isHref($inline->href), $message);
                self::assertSame(1, preg_match('~^https?://~i', $inline->href), $message . ': ' . $inline->href);
                $this->assertSafeInlines($inline->children, true, $message);
            } elseif ($inline instanceof Strong || $inline instanceof Emphasis) {
                $this->assertSafeInlines($inline->children, $inLink, $message);
            }
        }
    }
}
