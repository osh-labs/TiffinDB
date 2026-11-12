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
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUD-1: the audit log. */
#[CoversClass(AuditLog::class)]
#[CoversClass(AuditEntry::class)]
#[CoversClass(AuditAction::class)]
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
}
