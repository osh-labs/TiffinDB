<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\AuditChoices;
use TiffinDB\Core\App\AuditOperations;
use TiffinDB\Core\App\AuditPage;
use TiffinDB\Core\App\AuditRow;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\System\AuditAction;

require_once __DIR__ . '/AppTestCase.php';

/**
 * T-ADM-20 (M7 spec sections 7 and 16.1): the audit screen's filters
 * alone and together, the deleted-projects filter, paging and clamping,
 * ignored bad values, and the names of deactivated people and deleted
 * projects.
 */
#[CoversClass(AuditOperations::class)]
#[CoversClass(AuditPage::class)]
#[CoversClass(AuditRow::class)]
#[CoversClass(AuditChoices::class)]
final class AuditPageTest extends AppTestCase
{
    private string $garden;
    private string $attic;

    protected function setUp(): void
    {
        parent::setUp();
        $projects = $this->ops->projects();
        $this->garden = $projects->create($this->admin, 'Example Garden', null)->key;
        $this->attic = $projects->create($this->admin, 'Example Attic', null)->key;
        $projects->rename($this->admin, $this->garden, 'Example Allotment');
        $this->fx->signIn($this->ops, 'member');
        $projects->delete($this->admin, $this->attic, 'Example Attic', 0);
        // Actions so far, oldest first: project.created x2, project.renamed, auth.sign_in, project.deleted.
    }

    /** @param array<mixed> $query */
    private function page(array $query = []): AuditPage
    {
        return $this->ops->audit()->page($this->admin, $query);
    }

    /** @return list<string> */
    private static function actions(AuditPage $page): array
    {
        return array_map(static fn (AuditRow $r): string => $r->action, $page->rows);
    }

    public function testTheUnfilteredPageNamesPeopleAndProjects(): void
    {
        $page = $this->page();
        self::assertSame([AuditAction::PROJECT_DELETED, AuditAction::SIGN_IN, AuditAction::PROJECT_RENAMED, AuditAction::PROJECT_CREATED, AuditAction::PROJECT_CREATED], self::actions($page));
        self::assertSame([5, 1, 1], [$page->total, $page->page, $page->pages]);
        self::assertSame([null, null, null], [$page->person, $page->project, $page->action]);

        [$deleted, $signIn, $renamed, $attic, $garden] = $page->rows;
        self::assertSame(['Example Admin', $this->admin->userKey, 'Example Attic', 'project', $this->attic], [$deleted->actorName, $deleted->actorKey, $deleted->projectName, $deleted->targetKind, $deleted->targetKey]);
        self::assertSame(['Example Member', null], [$signIn->actorName, $signIn->projectName]);
        self::assertSame('Example Allotment', $renamed->projectName, 'The current name.');
        self::assertSame('Example Allotment', $garden->projectName);
        self::assertNull($attic->projectName, 'The deleted project row is gone; only project.deleted keeps its name.');
        self::assertSame(['name' => 'Example Attic', 'records' => 0, 'trashed' => 0, 'files' => 0, 'snapshots' => 0], $deleted->detail);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $deleted->at);
    }

    public function testEachFilterAloneAndTogether(): void
    {
        $garden = ['project' => $this->garden];
        $member = ['person' => $this->member->userKey];
        $created = ['action' => AuditAction::PROJECT_CREATED];
        $admin = ['person' => $this->admin->userKey];
        $cases = [
            'person' => [$member, [AuditAction::SIGN_IN]],
            'project' => [$garden, [AuditAction::PROJECT_RENAMED, AuditAction::PROJECT_CREATED]],
            'action' => [$created, [AuditAction::PROJECT_CREATED, AuditAction::PROJECT_CREATED]],
            'person and action' => [$admin + $created, [AuditAction::PROJECT_CREATED, AuditAction::PROJECT_CREATED]],
            'project and action' => [$garden + $created, [AuditAction::PROJECT_CREATED]],
            'person and project' => [$member + $garden, []],
            'all three' => [$admin + $garden + ['action' => AuditAction::PROJECT_RENAMED], [AuditAction::PROJECT_RENAMED]],
            'deleted projects' => [['project' => 'deleted'], [AuditAction::PROJECT_DELETED]],
            'deleted projects by a person' => [['project' => 'deleted'] + $member, []],
        ];
        foreach ($cases as $label => [$query, $expected]) {
            $page = $this->page($query);
            self::assertSame($expected, self::actions($page), $label);
            self::assertSame(count($expected), $page->total, $label);
            self::assertSame(1, $page->pages, $label);
            self::assertSame([$query['person'] ?? null, $query['project'] ?? null, $query['action'] ?? null], [$page->person, $page->project, $page->action], $label);
        }
    }

    public function testBadValuesAreIgnoredNeverAnError(): void
    {
        $bad = [
            ['person' => 'zzzzzzzzzzzz'], ['person' => 'NOT A KEY'], ['person' => ['x']], ['person' => 7],
            ['project' => $this->attic], ['project' => 'Deleted'], ['project' => ['deleted']], ['project' => "' OR 1=1 --"],
            ['action' => 'project.exploded'], ['action' => 'PROJECT.CREATED'], ['action' => null],
            ['page' => '0'], ['page' => '-1'], ['page' => '1e3'], ['page' => '01'], ['page' => '1234567'], ['page' => [2]], ['page' => 2],
            ['other' => 'x'],
        ];
        foreach ($bad as $query) {
            $page = $this->page($query);
            self::assertSame(5, $page->total, json_encode($query, JSON_THROW_ON_ERROR));
            self::assertSame([1, null, null, null], [$page->page, $page->person, $page->project, $page->action]);
        }
    }

    public function testPagingAndClamping(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->ops->snapshots()->take($this->admin, $this->garden);
        }
        $first = $this->page();
        self::assertSame([125, 1, 3], [$first->total, $first->page, $first->pages]);
        self::assertCount(AuditOperations::PAGE, $first->rows);
        self::assertSame(50, AuditOperations::PAGE);
        $last = $this->page(['page' => '3']);
        self::assertCount(25, $last->rows);
        self::assertSame(AuditAction::PROJECT_CREATED, $last->rows[24]->action);
        self::assertSame(3, $this->page(['page' => '99'])->page, 'Clamped to the last page.');
        self::assertEquals($last->rows, $this->page(['page' => '999999'])->rows);
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            foreach ($this->page(['page' => (string) $n])->rows as $row) {
                $ids[] = $row->id;
            }
        }
        self::assertCount(125, array_unique($ids), 'Every row once.');
        $snapshots = $this->page(['action' => AuditAction::SNAPSHOT_TAKEN, 'page' => '3']);
        self::assertSame([120, 3, 3, 20], [$snapshots->total, $snapshots->page, $snapshots->pages, count($snapshots->rows)]);
        $none = $this->page(['person' => $this->member->userKey, 'action' => AuditAction::SNAPSHOT_TAKEN, 'page' => '4']);
        self::assertSame([0, 1, 1, []], [$none->total, $none->page, $none->pages, $none->rows]);
    }

    public function testDeactivatedPeopleKeepTheirNameAndTheChoicesListEveryone(): void
    {
        $this->fx->setActive('member', false);
        $page = $this->page(['person' => $this->member->userKey]);
        self::assertSame('Example Member', $page->rows[0]->actorName, 'A deactivated person is still named.');
        self::assertSame($this->member->userKey, $page->person, 'And can still be chosen.');

        $choices = $page->choices;
        self::assertSame(
            [['key' => $this->member2->userKey, 'name' => 'Another Member', 'active' => true], ['key' => $this->admin->userKey, 'name' => 'Example Admin', 'active' => true], ['key' => $this->member->userKey, 'name' => 'Example Member', 'active' => false]],
            $choices->people,
        );
        self::assertSame([['key' => $this->garden, 'name' => 'Example Allotment']], $choices->projects, 'Active projects only; deleted ones are the `deleted` value.');
        self::assertSame(AuditAction::ALL, $choices->actions);
    }

    public function testValuesAreReturnedAsStored(): void
    {
        $this->fx->addPerson('example.markup', '<b>Example & Co</b>', Role::Admin);
        $markup = $this->fx->actor('example.markup');
        $this->ops->projects()->create($markup, '<i>Example</i> "Shed"', null);
        $row = $this->page(['person' => $markup->userKey])->rows[0];
        self::assertSame(['<b>Example & Co</b>', '<i>Example</i> "Shed"'], [$row->actorName, $row->projectName], 'Escaping is the template\'s.');
    }
}
