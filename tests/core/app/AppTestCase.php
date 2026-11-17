<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\TestCase;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\System\AuditEntry;
use TiffinDB\Tests\Support\CoreFixture;

/**
 * Shared set-up for the Core\App tests: a CoreFixture instance, one
 * Operations over it, and the three fixture actors.
 */
abstract class AppTestCase extends TestCase
{
    protected CoreFixture $fx;
    protected Operations $ops;
    protected Actor $admin;
    protected Actor $member;
    protected Actor $member2;
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->fx = new CoreFixture(static::class);
        $this->ops = $this->fx->operations();
        $this->admin = $this->fx->actor('admin');
        $this->member = $this->fx->actor('member');
        $this->member2 = $this->fx->actor('member2');
    }

    protected function tearDown(): void
    {
        if ($this->previousLog !== false) {
            ini_set('error_log', $this->previousLog);
        }
        $this->fx->remove();
    }

    /** PHPUnit captures error_log() from the start of the test body on; call this in the body to send it to a file instead. */
    protected function captureLog(): void
    {
        $previous = ini_set('error_log', $this->fx->temp->path('error.log'));
        if ($this->previousLog === false) {
            $this->previousLog = $previous;
        }
    }

    /** What the code under test wrote with error_log() since captureLog(). */
    protected function errorLog(): string
    {
        $path = $this->fx->temp->path('error.log');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * The problems $work throws, as [field, key, params]; fails when it does not throw ValidationFailed.
     *
     * @return list<array{0: ?string, 1: string, 2: array<string, string|int>}>
     */
    protected static function problems(callable $work): array
    {
        try {
            $work();
        } catch (ValidationFailed $failed) {
            return array_map(static fn (Problem $p): array => [$p->field, $p->key, $p->params], $failed->problems);
        }
        self::fail('ValidationFailed was expected.');
    }

    /** Audit entries, oldest first. @return list<AuditEntry> */
    protected function auditEntries(): array
    {
        return array_reverse($this->ops->audit()->recent($this->admin, 500));
    }

    /** @return list<string> actions, oldest first */
    protected function auditActions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action, $this->auditEntries());
    }

    protected function lastAudit(string $action): AuditEntry
    {
        foreach (array_reverse($this->auditEntries()) as $entry) {
            if ($entry->action === $action) {
                return $entry;
            }
        }
        self::fail('No audit entry ' . $action . '.');
    }

    /** Fails unless $work throws $class. */
    protected static function assertThrows(string $class, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error, 'Unexpected ' . $error::class);

            return;
        }
        self::fail($class . ' was expected.');
    }
}
