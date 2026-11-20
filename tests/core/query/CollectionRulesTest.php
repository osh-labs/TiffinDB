<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Query\CollectionRule;
use TiffinDB\Core\Query\CollectionRules;
use TiffinDB\Core\Query\Condition;
use TiffinDB\Core\Query\ListQuery;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Query\Operator;
use TiffinDB\Core\Query\RecordData;
use TiffinDB\Core\Query\ResolvedRules;
use TiffinDB\Core\Query\RuleSet;
use TiffinDB\Core\Query\RuleState;
use TiffinDB\Core\Query\Sort;

require_once __DIR__ . '/RecordsFixture.php';

/**
 * T-COL-5 to T-COL-9 (`docs/specs/M4-core.md` sections 6.3 and 12.3):
 * stored rules checked against the table as it is now, choice rules by
 * key, sort resolution, posted editor rows, and the tests offered per
 * field type. All data is synthetic.
 */
#[CoversClass(CollectionRules::class)]
#[CoversClass(ResolvedRules::class)]
#[CoversClass(RuleState::class)]
final class CollectionRulesTest extends TestCase
{
    use RecordsFixture;

    protected function setUp(): void
    {
        $this->setUpRecords('tiffindb-collection-rules-test-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownProject();
    }

    private function rules(): CollectionRules
    {
        return new CollectionRules($this->db, $this->schema(), $this->types);
    }

    private function rule(string $short, string $op, array $values = []): CollectionRule
    {
        return new CollectionRule($this->f[$short]->key, $op, $values);
    }

    /** The current key of a choice label of the "F choice" field. */
    private function choiceKey(string $label): string
    {
        foreach ($this->schema()->field($this->f['choice']->key)->options['choices'] as $choice) {
            if ($choice['label'] === $label) {
                return $choice['key'];
            }
        }
        self::fail('No choice ' . $label);
    }

    /** @param list<CollectionRule> $rules */
    private function resolve(array $rules, MatchMode $match = MatchMode::All, ?string $sort = null): ResolvedRules
    {
        return $this->rules()->resolve($this->items->key, $match, $rules, $sort);
    }

    /** @return list<?string> broken reasons in rule order */
    private static function reasons(ResolvedRules $resolved): array
    {
        return array_map(static fn (RuleState $s): ?string => $s->brokenReason, $resolved->states);
    }

    // ------------------------------------------------------------ T-COL-5

    public function testResolveGivesEveryBrokenReasonAndIgnoresBrokenRules(): void
    {
        $hall = $this->room('Hall');
        $gone = $this->room('Gone');
        $this->trashRecord($this->rooms, $gone);
        $red = $this->choiceKey('Red');
        $cases = [
            // Working rules, and the query form each becomes.
            [$this->rule('text', 'contains', [' lamp ']), null, ['lamp']],
            [$this->rule('email', 'is', ['User@Example.com']), null, ['User@Example.com']],
            [$this->rule('url', 'not-empty'), null, []],
            [$this->rule('number', 'less', ['12.5']), null, ['12.50']],
            [$this->rule('currency', 'more', ['329']), null, ['329.00']],
            [$this->rule('rating', 'is', ['5']), null, ['5']],
            [$this->rule('duration', 'is', ['90']), null, ['1:30:00']],
            [$this->rule('date', 'on', ['2023-01-01']), null, ['2023-01-01']],
            [$this->rule('date_time', 'after', ['2023-01-01']), null, ['2023-01-01']],
            [$this->rule('choice', 'is', [$red]), null, ['Red']],
            [$this->rule('choice', 'empty'), null, []],
            [$this->rule('checkbox', 'is', ['0']), null, ['0']],
            [$this->rule('stored', 'links-to', [(string) $hall]), null, [(string) $hall]],
            [$this->rule('room', 'empty'), null, []],
            // Broken rules, by the first reason that applies.
            [new CollectionRule('zzzzzzzzzzzz', 'is', ['x']), 'collection.broken.field', null],
            [$this->rule('room_name', 'contains', ['x']), 'collection.broken.field', null],
            [$this->rule('text', 'less', ['1']), 'collection.broken.type', null],
            [$this->rule('date', 'between', ['2026-01-01', '']), 'collection.broken.type', null],
            [$this->rule('checkbox', 'empty'), 'collection.broken.type', null],
            [$this->rule('time', 'is', ['10:00']), 'collection.broken.type', null],
            [$this->rule('auto', 'empty'), 'collection.broken.type', null],
            [$this->rule('files', 'empty'), 'collection.broken.type', null],
            [$this->rule('text', 'empty', ['x']), 'collection.broken.value', null],
            [$this->rule('text', 'not-empty', ['']), 'collection.broken.value', null],
            [$this->rule('text', 'is'), 'collection.broken.value', null],
            [$this->rule('text', 'is', ['a', 'b']), 'collection.broken.value', null],
            [$this->rule('text', 'contains', ['   ']), 'collection.broken.value', null],
            [$this->rule('text', 'contains', ["bell\u{0007}"]), 'collection.broken.value', null],
            [$this->rule('text', 'contains', ["a\0b"]), 'collection.broken.value', null],
            [$this->rule('number', 'is', ['twelve']), 'collection.broken.value', null],
            [$this->rule('rating', 'is', ['6']), 'collection.broken.value', null],
            [$this->rule('duration', 'more', ['-5']), 'collection.broken.value', null],
            [$this->rule('date', 'on', ['2026-02-30']), 'collection.broken.value', null],
            [$this->rule('date_time', 'before', ['tomorrow']), 'collection.broken.value', null],
            [$this->rule('choice', 'is', ['zzzzzzzzzzzz']), 'collection.broken.choice', null],
            [$this->rule('choice', 'is', ['Red']), 'collection.broken.choice', null],
            [$this->rule('checkbox', 'is', ['yes']), 'collection.broken.value', null],
            [$this->rule('stored', 'links-to', [(string) $gone]), 'collection.broken.record', null],
            [$this->rule('stored', 'links-to', ['999']), 'collection.broken.record', null],
            [$this->rule('stored', 'links-to', ['0' . $hall]), 'collection.broken.record', null],
            [$this->rule('room', 'links-to', ['1 OR 1']), 'collection.broken.record', null],
        ];
        foreach ($cases as $i => [$rule, $reason, $operands]) {
            $resolved = $this->resolve([$rule]);
            self::assertSame([$reason], self::reasons($resolved), 'case ' . $i);
            self::assertSame($reason === null ? 0 : 1, $resolved->broken);
            if ($reason === null) {
                self::assertNotNull($resolved->set);
                self::assertEquals([new Condition($rule->field, Operator::from($rule->op), $operands)], $resolved->set->conditions, 'case ' . $i);
                // Every resolved set lists without error.
                $this->records()->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1, $resolved->set));
            } else {
                self::assertNull($resolved->set, 'case ' . $i);
            }
        }

        // Broken rules are kept in order with their reason and left out of the set.
        $a = $this->item(['name' => 'a', 'checkbox' => '1']);
        $this->item(['name' => 'b']);
        $mixed = [$this->rule('text', 'less', ['1']), $this->rule('checkbox', 'is', ['1']), $this->rule('choice', 'is', ['gone-choice1'])];
        $resolved = $this->resolve($mixed, MatchMode::Any);
        self::assertSame(['collection.broken.type', null, 'collection.broken.choice'], self::reasons($resolved));
        self::assertSame(2, $resolved->broken);
        self::assertSame($mixed, array_map(static fn (RuleState $s): CollectionRule => $s->rule, $resolved->states));
        self::assertNotNull($resolved->set);
        self::assertSame(MatchMode::Any, $resolved->set->match);
        $page = $this->records()->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1, $resolved->set));
        self::assertSame([$a], array_map(static fn (RecordData $r): int => $r->id, $page->records));

        // No rules, or only broken ones: no set (the collection lists every live record).
        self::assertEquals(new ResolvedRules(null, [], 0, null), $this->resolve([]));
        $allBroken = $this->resolve([$mixed[0], $mixed[2]]);
        self::assertSame([null, 2], [$allBroken->set, $allBroken->broken]);
    }

    public function testChangesToTheTableBreakRules(): void
    {
        $editor = $this->editor();
        $rating = $this->rule('rating', 'is', ['5']);
        $number = $this->rule('number', 'less', ['3']);
        self::assertSame([null, null], self::reasons($this->resolve([$rating, $number])));
        // A lowered rating maximum, a changed type, a deleted field.
        $editor->updateField($this->f['rating']->key, 'F rating', ['max' => 3], false, '', 0);
        $editor->changeType($this->f['number']->key, 'text', [], 0);
        self::assertSame(['collection.broken.value', 'collection.broken.type'], self::reasons($this->resolve([$rating, $number])));
        $editor->deleteField($this->f['rating']->key);
        self::assertSame(['collection.broken.field'], self::reasons($this->resolve([$rating])));
        // A record put in the trash breaks a links-to rule; restored, the rule works again.
        $hall = $this->room('Hall');
        $links = $this->rule('stored', 'links-to', [(string) $hall]);
        $this->trashRecord($this->rooms, $hall);
        self::assertSame(['collection.broken.record'], self::reasons($this->resolve([$links])));
        $this->db->run('UPDATE ' . $this->rooms->sql() . ' SET "_trashed_at" = NULL WHERE "id" = ?', [$hall]);
        self::assertSame([null], self::reasons($this->resolve([$links])));
    }

    // ------------------------------------------------------------ T-COL-6

    public function testChoiceRulesFollowTheKey(): void
    {
        $red = $this->choiceKey('Red');
        $green = $this->choiceKey('Green');
        $blue = $this->choiceKey('Blue');
        $a = $this->item(['name' => 'a', 'choice' => 'Red']);
        $this->item(['name' => 'b', 'choice' => 'Green']);
        $rule = $this->rule('choice', 'is', [$red]);
        $listed = function () use ($rule): array {
            $set = $this->resolve([$rule])->set;
            self::assertNotNull($set);

            return array_map(static fn (RecordData $r): int => $r->id, $this->records()->page($this->items->key, new ListQuery(new Sort('added', false), null, null, 1, $set))->records);
        };
        self::assertSame([$a], $listed());

        // Renaming the choice keeps the rule working; the query uses the new label.
        $this->editor()->updateField($this->f['choice']->key, 'F choice', ['choices' => [
            ['key' => $red, 'label' => 'Crimson'], ['key' => $green, 'label' => 'Green'], ['key' => $blue, 'label' => 'Blue'],
        ]], false, '', 0);
        self::assertEquals([new Condition($this->f['choice']->key, Operator::Is, ['Crimson'])], $this->resolve([$rule])->set?->conditions);
        self::assertSame([$a], $listed());

        // Removing it breaks the rule.
        $this->editor()->updateField($this->f['choice']->key, 'F choice', ['choices' => [
            ['key' => $green, 'label' => 'Green'], ['key' => $blue, 'label' => 'Blue'],
        ]], false, '', 1);
        self::assertSame(['collection.broken.choice'], self::reasons($this->resolve([$rule])));
    }

    // ------------------------------------------------------------ T-COL-7

    public function testSortResolution(): void
    {
        $name = $this->f['name']->key;
        $cases = [
            [null, null],
            ['added.asc', new Sort('added', false)],
            ['added.desc', new Sort('added', true)],
            ['changed.desc', new Sort('changed', true)],
            ['changed.asc', null],
            [$name . '.asc', new Sort($name, false)],
            [$name . '.desc', new Sort($name, true)],
            [$this->f['stored']->key . '.asc', new Sort($this->f['stored']->key, false)],
            [$this->f['files']->key . '.asc', null],
            [$this->f['room_name']->key . '.asc', null],
            ['zzzzzzzzzzzz.asc', null],
            [$name . '.up', null],
            ['', null],
            ['Added.asc', null],
        ];
        foreach ($cases as [$token, $expected]) {
            self::assertEquals($expected, $this->resolve([], MatchMode::All, $token)->sort, (string) $token);
        }
        // A deleted field's token resolves to the default order.
        $text = $this->f['text']->key;
        self::assertEquals(new Sort($text, true), $this->resolve([], MatchMode::All, $text . '.desc')->sort);
        $this->editor()->deleteField($text);
        self::assertNull($this->resolve([], MatchMode::All, $text . '.desc')->sort);
    }

    // ------------------------------------------------------------ T-COL-8

    public function testFromInput(): void
    {
        $rules = $this->rules();
        $hall = $this->room('Hall');
        $gone = $this->room('Gone');
        $this->trashRecord($this->rooms, $gone);
        $red = $this->choiceKey('Red');
        $ok = [
            ['text', 'contains', ['value' => '  lamp '], 'contains', ['lamp']],
            ['text', 'is', ['value' => 'Lamp'], 'is', ['Lamp']],
            ['long_text', 'empty', ['value' => 'ignored'], 'empty', []],
            ['phone', 'not-empty', [], 'not-empty', []],
            ['number', 'is', ['value' => '12.5'], 'is', ['12.50']],
            ['number', 'less', ['value' => '-3'], 'less', ['-3.00']],
            ['currency', 'more', ['value' => '329'], 'more', ['329.00']],
            ['rating', 'is', ['value' => '4'], 'is', ['4']],
            ['duration', 'less', ['value' => '1:30'], 'less', ['1:30:00']],
            ['number', 'empty', [], 'empty', []],
            ['date', 'on', ['value' => '2023-01-01'], 'on', ['2023-01-01']],
            ['date', 'before', ['value' => ' 2023-01-01 '], 'before', ['2023-01-01']],
            ['date_time', 'after', ['value' => '2023-01-01'], 'after', ['2023-01-01']],
            ['date_time', 'empty', [], 'empty', []],
            ['choice', 'is', ['value' => $red], 'is', [$red]],
            ['choice', 'empty', [], 'empty', []],
            ['checkbox', 'ticked', [], 'is', ['1']],
            ['checkbox', 'not-ticked', ['value' => 'x'], 'is', ['0']],
            ['stored', 'links-to', ['record' => (string) $hall], 'links-to', [(string) $hall]],
            ['room', 'empty', [], 'empty', []],
        ];
        foreach ($ok as $i => [$short, $test, $extra, $op, $values]) {
            $result = $rules->fromInput($this->items->key, ['field' => $this->f[$short]->key, 'test' => $test, ...$extra], 3);
            self::assertEquals(new CollectionRule($this->f[$short]->key, $op, $values), $result, 'case ' . $i);
            // What fromInput stores resolves as working.
            self::assertInstanceOf(CollectionRule::class, $result);
            self::assertSame([null], self::reasons($this->resolve([$result])), 'case ' . $i);
        }

        $problem = static fn (string $key, array $params = []): array => [new Problem('rule-3', $key, $params)];
        $refused = [
            [['test' => 'is'], $problem('problem.collection.field')],
            [['field' => '', 'test' => 'is'], $problem('problem.collection.field')],
            [['field' => 'zzzzzzzzzzzz', 'test' => 'is'], $problem('problem.collection.field')],
            [['field' => $this->f['room_name']->key, 'test' => 'is'], $problem('problem.collection.field')],
            [['field' => $this->f['time']->key, 'test' => 'is', 'value' => '10:00'], $problem('problem.collection.field')],
            [['field' => $this->f['auto']->key, 'test' => 'empty'], $problem('problem.collection.field')],
            [['field' => $this->f['files']->key, 'test' => 'empty'], $problem('problem.collection.field')],
            [['field' => [$this->f['text']->key], 'test' => 'is'], $problem('problem.collection.field')],
            [['field' => $this->f['text']->key], $problem('problem.collection.test')],
            [['field' => $this->f['text']->key, 'test' => 'less', 'value' => '1'], $problem('problem.collection.test')],
            [['field' => $this->f['text']->key, 'test' => 'IS', 'value' => '1'], $problem('problem.collection.test')],
            [['field' => $this->f['checkbox']->key, 'test' => 'is', 'value' => '1'], $problem('problem.collection.test')],
            [['field' => $this->f['date']->key, 'test' => 'between', 'value' => '2023-01-01'], $problem('problem.collection.test')],
            [['field' => $this->f['text']->key, 'test' => 'contains'], $problem('problem.collection.value')],
            [['field' => $this->f['text']->key, 'test' => 'contains', 'value' => '   '], $problem('problem.collection.value')],
            [['field' => $this->f['text']->key, 'test' => 'contains', 'value' => ['lamp']], $problem('problem.collection.value')],
            [['field' => $this->f['text']->key, 'test' => 'is', 'value' => str_repeat('a', 201)], $problem('problem.collection.text', ['max' => 200])],
            [['field' => $this->f['text']->key, 'test' => 'is', 'value' => "a\0b"], $problem('problem.collection.text', ['max' => 200])],
            [['field' => $this->f['text']->key, 'test' => 'is', 'value' => "\xC3"], $problem('problem.collection.text', ['max' => 200])],
            [['field' => $this->f['number']->key, 'test' => 'is', 'value' => 'twelve'], $problem('problem.collection.number')],
            [['field' => $this->f['number']->key, 'test' => 'is'], $problem('problem.collection.value')],
            [['field' => $this->f['rating']->key, 'test' => 'is', 'value' => '6'], $problem('problem.collection.number')],
            [['field' => $this->f['duration']->key, 'test' => 'is', 'value' => 'long'], $problem('problem.collection.number')],
            [['field' => $this->f['currency']->key, 'test' => 'is', 'value' => "1\xC3"], $problem('problem.collection.number')],
            [['field' => $this->f['date']->key, 'test' => 'on', 'value' => '2026-02-30'], $problem('problem.collection.date')],
            [['field' => $this->f['date_time']->key, 'test' => 'on', 'value' => 'soon'], $problem('problem.collection.date')],
            [['field' => $this->f['choice']->key, 'test' => 'is', 'value' => 'Red'], $problem('problem.collection.choice')],
            [['field' => $this->f['choice']->key, 'test' => 'is', 'value' => ''], $problem('problem.collection.value')],
            [['field' => $this->f['stored']->key, 'test' => 'links-to'], $problem('problem.collection.record')],
            [['field' => $this->f['stored']->key, 'test' => 'links-to', 'record' => (string) $gone], $problem('problem.collection.record')],
            [['field' => $this->f['stored']->key, 'test' => 'links-to', 'record' => '999'], $problem('problem.collection.record')],
            [['field' => $this->f['stored']->key, 'test' => 'links-to', 'record' => ' ' . $hall], $problem('problem.collection.record')],
            [['field' => $this->f['stored']->key, 'test' => 'links-to', 'value' => (string) $hall], $problem('problem.collection.record')],
        ];
        foreach ($refused as $i => [$row, $expected]) {
            self::assertEquals($expected, $rules->fromInput($this->items->key, $row, 3), 'case ' . $i . ' ' . json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertEquals([new Problem('rule-1', 'problem.collection.field')], $rules->fromInput($this->items->key, []));
    }

    // ------------------------------------------------------------ T-COL-9

    public function testTestsForEachType(): void
    {
        $rules = $this->rules();
        $text = ['contains', 'is', 'empty', 'not-empty'];
        $number = ['is', 'less', 'more', 'empty'];
        $date = ['on', 'before', 'after', 'empty'];
        $expected = [
            'name' => $text, 'text' => $text, 'long_text' => $text, 'url' => $text, 'email' => $text, 'phone' => $text,
            'number' => $number, 'currency' => $number, 'rating' => $number, 'duration' => $number,
            'date' => $date, 'date_time' => $date, 'choice' => ['is', 'empty'], 'checkbox' => ['ticked', 'not-ticked'],
            'stored' => ['links-to', 'empty'], 'room' => ['links-to', 'empty'], 'parts' => ['links-to', 'empty'], 'room_items' => ['links-to', 'empty'],
            'time' => [], 'auto' => [], 'files' => [],
        ];
        foreach ($expected as $short => $ops) {
            $tests = $rules->testsFor($this->f[$short]);
            self::assertSame($ops, array_column($tests, 'op'), $short);
            self::assertSame(array_map(static fn (string $op): string => 'collections.test.' . $op, $ops), array_column($tests, 'label'), $short);
        }
    }

    public function testResolveRefusesWhatIsNotARuleList(): void
    {
        foreach ([[['field' => 'x']], array_fill(0, 11, $this->rule('text', 'empty')), [1 => $this->rule('text', 'empty')]] as $i => $rules) {
            try {
                $this->resolve($rules);
                self::fail('Case ' . $i . ' accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(\TiffinDB\Core\Error\NotFound::class);
        $this->rules()->resolve('zzzzzzzzzzzz', MatchMode::All, [], null);
    }

    /** RuleSet is what resolve() gives, never more than ten conditions. */
    public function testTenWorkingRulesResolveToOneSet(): void
    {
        $resolved = $this->resolve(array_fill(0, 10, $this->rule('checkbox', 'is', ['1'])), MatchMode::Any);
        self::assertInstanceOf(RuleSet::class, $resolved->set);
        self::assertCount(10, $resolved->set->conditions);
    }
}
