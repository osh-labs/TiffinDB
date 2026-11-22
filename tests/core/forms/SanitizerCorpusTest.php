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
use TiffinDB\Core\Forms\FormDocument;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormRules;
use TiffinDB\Core\Forms\FormSerializer;
use TiffinDB\Core\Forms\StartTags;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;

require_once __DIR__ . '/corpus/Corpus.php';

/**
 * T-SAN-1 and T-SAN-2 (M5 spec sections 11.1 and 12): every corpus case
 * gives its recorded verdict, reason, line, and names, and every accepted
 * case its canonical text; the corpus covers every reason code, every case
 * of the catalogue, and every class of the session plan's corpus row.
 */
#[CoversClass(FormParser::class)]
#[CoversClass(FormRejected::class)]
#[CoversClass(FormRules::class)]
#[CoversClass(StartTags::class)]
final class SanitizerCorpusTest extends TestCase
{
    /**
     * Ids of the catalogue (section 12.2) that must exist. Extra cases
     * (A25 on, B30 on, and so on) are additions of the implementation.
     */
    private const CATALOGUE = [
        'A' => 24, 'B' => 29, 'C' => 30, 'L' => 39, 'T' => 35, 'E' => 14, 'X' => 14, 'D' => 20, 'S' => 17, 'Z' => 9,
    ];

    /** The classes of the session plan's corpus row (plan 6.2 row 12), each with cases that stand for it. */
    private const CLASSES = [
        'script and event attributes' => ['A1', 'A2', 'B1', 'B2', 'B3', 'B4'],
        'style, class, id' => ['A3', 'B5', 'B6', 'B7'],
        'foreign elements' => ['A4', 'A5', 'A7', 'A8', 'A12', 'A17', 'A18', 'A19'],
        'DOCTYPE and entity attacks' => ['X1', 'X2', 'X3', 'X4', 'X5', 'X13'],
        'processing instructions' => ['X7', 'X8', 'X9'],
        'CDATA' => ['X6'],
        'namespaces and prefixed attributes' => ['A20', 'B17', 'B18', 'B19', 'B20', 'B21'],
        'script addresses with disguises' => ['L5', 'L6', 'L7', 'L8', 'L11', 'L12', 'L13', 'L14', 'L15', 'L16', 'L43', 'L48'],
        'control and bidirectional characters' => ['T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12', 'C5'],
        'invalid UTF-8 and other encodings' => ['E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E10'],
        'limits at and over the edge' => ['Z1', 'Z2', 'Z3', 'Z4', 'Z5', 'Z6', 'Z7'],
        'duplicate attributes and fields' => ['B22', 'B23', 'S13'],
        'HTML and XML read differently' => ['D1', 'D2', 'D3', 'D5', 'D8', 'D11', 'D12', 'D13', 'D14', 'D16', 'D17'],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cases = null;

    /** @return array<string, array<string, mixed>> */
    private static function cases(): array
    {
        return self::$cases ??= Corpus::all();
    }

    /** @return iterable<string, array{string}> */
    public static function ids(): iterable
    {
        foreach (array_keys(self::cases()) as $id) {
            yield $id => [$id];
        }
    }

    private static function parser(): FormParser
    {
        return new FormParser(FieldTypes::standard());
    }

    // ------------------------------------------------------------ T-SAN-1

    #[DataProvider('ids')]
    public function testCaseGivesItsRecordedResult(string $id): void
    {
        $case = self::cases()[$id];
        self::assertIsString($case['text']);
        try {
            $document = ($case['stage'] ?? null) === 'reader' ? self::readerStage($case) : self::parser()->parse($case['text']);
        } catch (FormRejected $rejected) {
            self::assertSame('refuse', $case['verdict'], $id . ' was refused with ' . $rejected->reason);
            self::assertSame($case['reason'], $rejected->reason, $id);
            self::assertSame('The form does not follow form format 1.', $rejected->getMessage(), $id);
            foreach (['line' => $rejected->formLine, 'element' => $rejected->element, 'attribute' => $rejected->attribute] as $key => $actual) {
                if (array_key_exists($key, $case)) {
                    self::assertSame($case[$key], $actual, $id . ' ' . $key);
                }
            }

            return;
        }
        self::assertSame('accept', $case['verdict'], $id . ' was accepted');
        self::assertInstanceOf(FormDocument::class, $document);
        if (array_key_exists('canonical', $case)) {
            self::assertSame($case['canonical'], (new FormSerializer())->serialize($document), $id);
        }
    }

    /**
     * The reader stage alone (FormParser::walk, after the pre-scan), with
     * libxml errors collected as parse() collects them. parse() itself must
     * refuse the same text with the case's `parse` reason.
     *
     * @param array<string, mixed> $case
     */
    private static function readerStage(array $case): FormDocument
    {
        $parser = self::parser();
        try {
            $parser->parse($case['text']);
            self::fail($case['id'] . ': parse() accepted the text.');
        } catch (FormRejected $rejected) {
            self::assertSame($case['parse'], $rejected->reason, $case['id']);
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = (new \ReflectionMethod(FormParser::class, 'walk'))->invoke($parser, $case['text']);
            self::assertInstanceOf(FormDocument::class, $document);

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    // ------------------------------------------------------------ T-SAN-2

    public function testEveryReasonIsCovered(): void
    {
        $reasons = [];
        foreach (self::cases() as $case) {
            if ($case['verdict'] === 'refuse') {
                self::assertContains($case['reason'], FormRejected::REASONS, $case['id']);
                $reasons[$case['reason']] = true;
            }
        }
        $missing = array_values(array_diff(FormRejected::REASONS, array_keys($reasons)));
        self::assertSame([], $missing, 'Reasons without a corpus case');

        $constants = [];
        foreach ((new \ReflectionClass(FormRejected::class))->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if (is_string($constant->getValue()) && $constant->getName() !== 'NAME') {
                $constants[] = $constant->getValue();
            }
        }
        self::assertEqualsCanonicalizing($constants, FormRejected::REASONS);
    }

    public function testEveryCatalogueCaseIsPresent(): void
    {
        $cases = self::cases();
        foreach (self::CATALOGUE as $letter => $count) {
            for ($n = 1; $n <= $count; $n++) {
                self::assertArrayHasKey($letter . $n, $cases);
            }
        }
        self::assertArrayHasKey('T13b', $cases);
        foreach ($cases as $id => $case) {
            self::assertContains($case['verdict'], ['accept', 'refuse'], $id);
            self::assertSame($case['verdict'] === 'refuse', array_key_exists('reason', $case), $id);
            self::assertSame(1, preg_match('/^[A-Z][0-9]+b?\z/', $id), $id);
        }
    }

    public function testEveryClassOfThePlanIsCovered(): void
    {
        $cases = self::cases();
        foreach (self::CLASSES as $class => $ids) {
            foreach ($ids as $id) {
                self::assertArrayHasKey($id, $cases, $class);
            }
        }
        // Script and active-content cases are all refusals.
        foreach (['script and event attributes', 'foreign elements', 'DOCTYPE and entity attacks', 'script addresses with disguises'] as $class) {
            foreach (self::CLASSES[$class] as $id) {
                self::assertSame('refuse', $cases[$id]['verdict'], $id);
            }
        }
    }

    /** Hostile cases carry the marker so the privacy test (T-SAN-5) has something to look for. */
    public function testMostRefusedCasesHoldTheMarker(): void
    {
        $refused = 0;
        $marked = 0;
        foreach (self::cases() as $case) {
            if ($case['verdict'] !== 'refuse') {
                continue;
            }
            $refused++;
            if (str_contains($case['text'], Corpus::MARKER)) {
                $marked++;
            }
        }
        self::assertGreaterThan(0.9 * $refused, $marked);
    }

    /**
     * Each value and placement rule has a refused case that breaks only that
     * rule; the case is accepted once the offending value is replaced by an
     * allowed one (a scripted form of the plan 6.5 mutation property).
     */
    public function testSingleRuleCasesPassWithTheValueFixed(): void
    {
        $fixes = [
            'C2' => ['data-tiffin-label="' . str_repeat('L', 59) . "\u{E9}x" . '"', 'data-tiffin-label="Name"'],
            'C7' => ['data-tiffin-col="0"', 'data-tiffin-col="1"'],
            'C12' => ['data-tiffin-row="51"', 'data-tiffin-row="50"'],
            'C13' => ['data-tiffin-span="FULL"', 'data-tiffin-span="full"'],
            'C18' => ['M4PZ7K2QSW3N', Corpus::KEY],
            'C20' => ['data-tiffin-section="Details"', 'data-tiffin-section="details"'],
            'C21' => ['attachment:0', 'attachment:1'],
            'L5' => ['javascript:alert(1)', 'https://example.com/'],
            'L27' => ['user:pw@', ''],
            'T8' => ['&#x202E;', ''],
            'T29' => ['&#xFDD0;', ''],
            'B1' => [' onclick="' . Corpus::MARKER . '"', ''],
            'A1' => ['<script>' . Corpus::MARKER . '</script>', '<p>' . Corpus::MARKER . '</p>'],
            'S13' => ['<div data-tiffin-field="' . Corpus::KEY . '" data-tiffin-label', '<div data-tiffin-field="' . Corpus::KEY2 . '" data-tiffin-label'],
            'T19' => ['>' . Corpus::MARKER . '<', '><p>' . Corpus::MARKER . '</p><'],
        ];
        $parser = self::parser();
        foreach ($fixes as $id => [$from, $to]) {
            $text = self::cases()[$id]['text'];
            self::assertStringContainsString($from, $text, $id);
            $parser->parse(str_replace($from, $to, $text));
        }
        $this->addToAssertionCount(count($fixes));
    }
}
