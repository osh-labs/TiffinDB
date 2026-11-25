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
use TiffinDB\Core\Forms\FormGenerator;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormRejected;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus;
use TiffinDB\Tests\Core\Schema\ProjectFixture;
use TiffinDB\Tests\Support\SeededRandom;

require_once __DIR__ . '/corpus/Corpus.php';
require_once __DIR__ . '/../schema/ProjectFixture.php';

/**
 * T-SAN-5 (M5 spec section 11.1): no content of a refused form reaches the
 * exception message, the message key, the parameters other than the
 * element and attribute names, or the log. Names that are not XML-name-like
 * (or longer than 40 characters) are never kept. Synthetic data only.
 */
#[CoversClass(FormRejected::class)]
#[CoversClass(FormParser::class)]
final class FormRejectionPrivacyTest extends TestCase
{
    use ProjectFixture;

    private const SEED = 'tiffindb-form-privacy-test-1';

    private string $logFile = '';
    private string|false|null $previousLog = null;

    protected function setUp(): void
    {
        $this->setUpProject(self::SEED);
        $this->logFile = $this->temp->path('php-error.log');
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== null) {
            ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        }
        $this->tearDownProject();
    }

    /** @return iterable<string, array{string}> */
    public static function markedRefusals(): iterable
    {
        foreach (Corpus::all() as $id => $case) {
            // Cases for the reader stage alone are refused by parse() with another reason; they are covered as well.
            if ($case['verdict'] === 'refuse' && str_contains($case['text'], Corpus::MARKER)) {
                yield $id => [$id];
            }
        }
    }

    private static function rejection(string $text): FormRejected
    {
        try {
            (new FormParser(FieldTypes::standard()))->parse($text);
        } catch (FormRejected $rejected) {
            return $rejected;
        }
        self::fail('The form was accepted.');
    }

    #[DataProvider('markedRefusals')]
    public function testRejectionHoldsNoContent(string $id): void
    {
        $rejected = self::rejection(Corpus::all()[$id]['text']);
        self::assertSame('The form does not follow form format 1.', $rejected->getMessage());
        self::assertStringNotContainsString(Corpus::MARKER, $rejected->messageKey());
        self::assertSame(1, preg_match('/^problem\.form\.[a-z_]+\z/', $rejected->messageKey()));
        $params = $rejected->params();
        self::assertSame([], array_diff(array_keys($params), ['line', 'element', 'attribute']));
        if (array_key_exists('line', $params)) {
            self::assertIsInt($params['line']);
        }
        foreach (['element', 'attribute'] as $name) {
            $value = $rejected->{$name};
            self::assertTrue($value === null || preg_match(FormRejected::NAME, $value) === 1, $id . ' ' . $name);
            self::assertSame($value, $params[$name] ?? null);
        }
    }

    public function testNamesThatAreNotNameLikeAreDropped(): void
    {
        foreach ([str_repeat('a', 41), '1abc', '-x', 'a b', 'a<b', 'a"b', "a\u{E9}", '', 'onclick=x', "a\nb", Corpus::MARKER . '"><'] as $bad) {
            $rejected = new FormRejected(FormRejected::ATTRIBUTE, 3, $bad, $bad);
            self::assertNull($rejected->element, $bad);
            self::assertNull($rejected->attribute, $bad);
            self::assertSame(['line' => 3], $rejected->params());
            self::assertSame('problem.form.attribute_unnamed', $rejected->messageKey());
        }
        foreach ([str_repeat('a', 40), 'xml:lang', '_x', 'aria-label', 'x.y', 'A1'] as $good) {
            $rejected = new FormRejected(FormRejected::ELEMENT, null, $good);
            self::assertSame($good, $rejected->element);
            self::assertSame(['element' => $good], $rejected->params());
        }
        // A24 and B29: the corpus's long names come back as null.
        $cases = Corpus::all();
        self::assertNull(self::rejection($cases['A24']['text'])->element);
        self::assertNull(self::rejection($cases['B29']['text'])->attribute);
    }

    public function testMessageKeys(): void
    {
        foreach (FormRejected::REASONS as $reason) {
            $named = new FormRejected($reason, 1, 'p', 'title');
            self::assertSame('problem.form.' . str_replace('-', '_', $reason), $named->messageKey());
            $unnamed = new FormRejected($reason);
            $expected = 'problem.form.' . str_replace('-', '_', $reason)
                . (in_array($reason, [FormRejected::ELEMENT, FormRejected::MISPLACED, FormRejected::ATTRIBUTE], true) ? '_unnamed' : '');
            self::assertSame($expected, $unnamed->messageKey());
            self::assertSame([], $unnamed->params());
        }
        self::assertSame('problem.form.attribute_unnamed', (new FormRejected(FormRejected::ATTRIBUTE, 1, 'p'))->messageKey());
        self::assertSame('problem.form.attribute_unnamed', (new FormRejected(FormRejected::ATTRIBUTE, 1, null, 'title'))->messageKey());
        self::assertSame('problem.form.too_many_elements', (new FormRejected(FormRejected::TOO_MANY_ELEMENTS))->messageKey());
    }

    /** FormStore::effective logs one line per refused stored form, with the reason and table key only. */
    public function testStoredRefusedFormsAreLoggedWithoutContent(): void
    {
        $this->previousLog = ini_set('error_log', $this->logFile);
        $table = $this->editor()->createTable('Items', 'item', true);
        $schema = new Schema($this->db, $this->types);
        $generated = (new FormGenerator())->generate($schema, $table->key);
        $store = new FormStore($this->db, new Keys(new SeededRandom(self::SEED . '-forms')), $this->clock, new FormParser($this->types));
        $this->db->write(static fn (Database $db) => $db->run(
            'INSERT INTO "_tiffin_forms" ("key", "table_key", "name", "format", "html", "css", "is_default", "generated", "created_at", "updated_at")'
            . ' VALUES (\'f7qk2mz4xn3w\', ?, \'Edited form\', 1, \'\', \'\', 1, 0, \'2026-11-12T03:15:00Z\', \'2026-11-12T03:15:00Z\')',
            [$table->key],
        ));

        $checked = 0;
        foreach (self::markedRefusals() as [$id]) {
            $case = Corpus::all()[$id];
            $reason = $case['parse'] ?? $case['reason'];
            $this->db->write(static fn (Database $db) => $db->run('UPDATE "_tiffin_forms" SET "html" = ?', [$case['text']]));
            file_put_contents($this->logFile, '');

            self::assertEquals($generated, $store->effective($schema, $table->key), $id);

            $log = (string) file_get_contents($this->logFile);
            self::assertStringNotContainsString(Corpus::MARKER, $log, $id);
            $lines = array_values(array_filter(explode("\n", $log), static fn (string $l): bool => $l !== ''));
            self::assertCount(1, $lines, $id);
            self::assertSame(1, preg_match(
                '/\] TiffinDB: stored form not used; reason=' . preg_quote($reason, '/') . '; table_key=' . $table->key . '\z/',
                $lines[0],
            ), $id . ': ' . $lines[0]);
            $checked++;
        }
        self::assertGreaterThan(200, $checked);
    }
}
