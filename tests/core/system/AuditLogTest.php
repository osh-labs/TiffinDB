<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditEntry;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\AuditQuery;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUD-1: the audit log; T-ADM-20 (storage part): search and count (M7 spec section 7.2). */
#[CoversClass(AuditLog::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(AuditAction::class)]
#[CoversClass(AuditQuery::class)]
final class AuditLogTest extends TestCase
{
    private TempDir $temp;
    private FixedClock $clock;
    private SystemDatabase $system;
    private AuditLog $audit;
    private string $logFile;
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-01-01T00:00:00Z');
        $this->system = SystemDatabase::create(
            new DataDirectory($this->temp->path(), 'data'),
            $this->clock,
            new SeededRandom('tiffindb-audit-log-test-seed-1'),
        );
        $this->audit = new AuditLog($this->system, $this->clock);
        $this->logFile = $this->temp->path('error.log');
    }

    /** PHPUnit captures error_log() from the start of the test body on; redirect it to a file this test reads. */
    private function captureLog(): void
    {
        $previous = ini_set('error_log', $this->logFile);
        if ($this->previousLog === false) {
            $this->previousLog = $previous;
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function logged(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function testRecentIsNewestFirstWithDetailDecoded(): void
    {
        $this->captureLog();
        $this->audit->record(AuditAction::PROJECT_CREATED, null, null, 'project', 'recipesaaaaa');
        $this->clock->advance(10);
        $this->audit->record(AuditAction::PEOPLE_UPDATED, null, null, 'user', 'personaaaaaa', [
            'role' => 'member', 'username_changed' => true, 'name_changed' => false,
        ]);
        $this->clock->advance(10);
        $this->audit->record(AuditAction::TRASH_EMPTIED, null, null, 'table', null, ['records' => 3, 'note' => null]);

        $entries = $this->audit->recent(10);
        $this->assertSame(
            [AuditAction::TRASH_EMPTIED, AuditAction::PEOPLE_UPDATED, AuditAction::PROJECT_CREATED],
            array_map(static fn (AuditEntry $e): string => $e->action, $entries),
        );
        $this->assertSame(['records' => 3, 'note' => null], $entries[0]->detail);
        $this->assertSame(['role' => 'member', 'username_changed' => true, 'name_changed' => false], $entries[1]->detail);
        $this->assertSame([], $entries[2]->detail);
        $this->assertSame('{}', $this->system->db()->value('SELECT detail FROM audit_log WHERE action = ?', [AuditAction::PROJECT_CREATED]));
        $this->assertSame('2026-01-01T00:00:20Z', $entries[0]->at);
        $this->assertSame('project', $entries[2]->targetKind);
        $this->assertSame('recipesaaaaa', $entries[2]->targetKey);
        $this->assertNull($entries[2]->actorUserId);

        $this->assertCount(1, $this->audit->recent(1, 2));
        $this->assertSame(AuditAction::PROJECT_CREATED, $this->audit->recent(1, 2)[0]->action);
        $this->assertSame('', $this->logged());
    }

    public function testOldFailedSignInsArePrunedOnTheNextFailedSignIn(): void
    {
        $this->audit->record(AuditAction::SIGN_IN_FAILED, null, null, null, null, ['reason' => 'unknown-user']);
        $this->audit->record(AuditAction::SIGN_IN, null, null, 'user', 'personaaaaaa');
        $this->clock->advance(89 * 86400);
        $this->audit->record(AuditAction::SIGN_IN_FAILED, null, null, null, null, ['reason' => 'bad-password']);
        $this->assertSame(2, $this->countAction(AuditAction::SIGN_IN_FAILED), 'Nothing is older than 90 days yet.');

        $this->clock->advance(2 * 86400);
        $this->audit->record(AuditAction::PEOPLE_CREATED, null, null, 'user', 'personaaaaaa', ['role' => 'member']);
        $this->assertSame(2, $this->countAction(AuditAction::SIGN_IN_FAILED), 'Other actions do not prune.');

        $this->audit->record(AuditAction::SIGN_IN_FAILED, null, null, null, null, ['reason' => 'throttled']);
        $this->assertSame(2, $this->countAction(AuditAction::SIGN_IN_FAILED), 'The 91-day-old one is gone.');
        $reasons = array_column(
            $this->system->db()->all('SELECT detail FROM audit_log WHERE action = ? ORDER BY id', [AuditAction::SIGN_IN_FAILED]),
            'detail',
        );
        $this->assertSame(['{"reason":"bad-password"}', '{"reason":"throttled"}'], $reasons);
        $this->assertSame(1, $this->countAction(AuditAction::SIGN_IN), 'Other actions are kept indefinitely.');
        $this->assertSame(1, $this->countAction(AuditAction::PEOPLE_CREATED));
    }

    public function testRecordOnAClosedDatabaseLogsOneLineAndDoesNotThrow(): void
    {
        $this->captureLog();
        $this->system->db()->close();
        $this->audit->record(AuditAction::SIGN_IN, 1, null, 'user', 'personaaaaaa');
        $log = $this->logged();
        $this->assertStringContainsString('could not record auth.sign_in (LogicException)', $log);
        $this->assertSame(1, substr_count(trim($log), "\n") + 1);
        $this->assertStringNotContainsString('personaaaaaa', $log);
        $this->assertStringNotContainsString($this->temp->path(), $log);
        // Reopen so tearDown can close it again.
        $this->system = SystemDatabase::open(new DataDirectory($this->temp->path(), 'data'));
    }

    public function testRecordInsideAnotherTransactionLogsAndDoesNotThrow(): void
    {
        $this->captureLog();
        $this->system->db()->write(fn () => $this->audit->record(AuditAction::SIGN_OUT, null));
        $this->assertSame(0, $this->countAction(AuditAction::SIGN_OUT));
        $this->assertStringContainsString('could not record auth.sign_out', $this->logged());
    }

    public function testUnknownActionsAndNonFlatDetailAreRefusedQuietly(): void
    {
        $this->captureLog();
        $this->audit->record('people.deleted_everything secret', null);
        $this->audit->record(AuditAction::SIGN_IN, null, null, null, null, ['nested' => ['a' => 1]]);
        $this->audit->record(AuditAction::SIGN_IN, null, null, null, null, ['Bad Key' => 1]);
        $this->assertSame(0, (int) $this->system->db()->value('SELECT COUNT(*) FROM audit_log'));
        $log = $this->logged();
        $this->assertStringNotContainsString('secret', $log);
        $this->assertStringContainsString('could not record an unknown action', $log);
        $this->assertSame(3, substr_count($log, 'TiffinDB audit log'));
    }

    private function countAction(string $action): int
    {
        return (int) $this->system->db()->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$action]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} two people and two projects, by id */
    private function scene(): array
    {
        $db = $this->system->db();
        $ids = [];
        foreach (['ann' => 'adminaaaaaaa', 'bea' => 'memberaaaaaa'] as $name => $key) {
            $db->run(
                "INSERT INTO users (key, username, username_norm, display_name, role, password_hash, created_at, updated_at, password_changed_at)
                 VALUES (?, ?, ?, 'Example Person', 'admin', 'x', ?, ?, ?)",
                [$key, $name, $name, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z'],
            );
            $ids[] = $db->lastInsertId();
        }
        foreach (['recipesaaaaa' => 'Recipes', 'gardenaaaaaa' => 'Garden'] as $key => $name) {
            $db->run("INSERT INTO projects (key, name, state, created_at) VALUES (?, ?, 'active', '2026-01-01T00:00:00Z')", [$key, $name]);
            $ids[] = $db->lastInsertId();
        }

        return $ids;
    }

    /** @return list<int> ids of the matches, newest first */
    private function ids(AuditQuery $q, int $limit = 100, int $offset = 0): array
    {
        return array_map(static fn (AuditEntry $e): int => $e->id, $this->audit->search($q, $limit, $offset));
    }

    public function testSearchFiltersAloneAndTogetherAndCounts(): void
    {
        [$ann, $bea, $recipes, $garden] = $this->scene();
        $rows = [
            [AuditAction::PROJECT_CREATED, $ann, $recipes],
            [AuditAction::PROJECT_RENAMED, $ann, $recipes],
            [AuditAction::PROJECT_CREATED, $bea, $garden],
            [AuditAction::SIGN_IN, $bea, null],
            [AuditAction::PROJECT_DELETED, $ann, null],
            [AuditAction::PROJECT_RENAMED, null, $garden],
            [AuditAction::SIGN_IN, null, null],
        ];
        foreach ($rows as [$action, $actor, $project]) {
            $this->audit->record($action, $actor, $project, null, null, $action === AuditAction::PROJECT_DELETED ? ['name' => 'Example Attic'] : []);
            $this->clock->advance(1);
        }
        $all = $this->ids(new AuditQuery());
        $this->assertCount(7, $all);
        $this->assertSame([7, 6, 5, 4, 3, 2, 1], $all, 'Newest first.');
        $cases = [
            'nothing' => [new AuditQuery(), [7, 6, 5, 4, 3, 2, 1]],
            'person' => [new AuditQuery($ann), [5, 2, 1]],
            'project' => [new AuditQuery(null, $garden), [6, 3]],
            'action' => [new AuditQuery(null, null, AuditAction::PROJECT_CREATED), [3, 1]],
            'person and project' => [new AuditQuery($bea, $garden), [3]],
            'person and action' => [new AuditQuery($ann, null, AuditAction::PROJECT_RENAMED), [2]],
            'project and action' => [new AuditQuery(null, $recipes, AuditAction::PROJECT_CREATED), [1]],
            'all three' => [new AuditQuery($bea, $recipes, AuditAction::PROJECT_CREATED), []],
            'deleted projects' => [new AuditQuery(null, null, null, true), [5]],
            'deleted projects by a person' => [new AuditQuery($bea, null, null, true), []],
            'deleted projects with another action' => [new AuditQuery(null, null, AuditAction::SIGN_IN, true), []],
        ];
        foreach ($cases as $label => [$query, $expected]) {
            $this->assertSame($expected, $this->ids($query), $label);
            $this->assertSame(count($expected), $this->audit->count($query), $label);
        }
        $this->assertSame([6, 5], $this->ids(new AuditQuery(), 2, 1));
        $this->assertSame([], $this->ids(new AuditQuery(), 2, 50));
        $this->assertSame(['name' => 'Example Attic'], $this->audit->search(new AuditQuery(null, null, null, true), 1, 0)[0]->detail);
    }

    public function testSearchOrdersTiesByIdAndRefusesNegativeBounds(): void
    {
        foreach ([AuditAction::SIGN_IN, AuditAction::SIGN_OUT, AuditAction::SIGN_IN] as $action) {
            $this->audit->record($action, null);
        }
        $this->assertSame([3, 2, 1], $this->ids(new AuditQuery()), 'The same time: by id, descending.');
        $this->assertSame(0, $this->audit->count(new AuditQuery(999)));
        $this->assertSame(0, $this->audit->count(new AuditQuery(null, null, "auth.sign_in' OR 1=1 --")), 'A value is bound, never joined.');
        $this->expectException(\InvalidArgumentException::class);
        $this->audit->search(new AuditQuery(), -1, 0);
    }
}
