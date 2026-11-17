<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Tests\Support\CoreFixture;
use TiffinDB\Tests\Support\RepoFiles;

/**
 * T-CLI-1: tools/admin/reset-password.php run as a local child process
 * (PHP_BINARY) against a CoreFixture data directory, with the passwords on
 * standard input. Refusal outside the command line is reviewed, not
 * tested (it needs a web server SAPI).
 */
#[CoversNothing]
final class ResetPasswordTest extends TestCase
{
    private const NEW = 'a fresh horse 33';

    private CoreFixture $fx;

    protected function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available, so no child process can be started.');
        }
        $this->fx = new CoreFixture('tiffindb-reset-password-test-seed-1');
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->remove();
        }
    }

    public function testResetsThePasswordEndsSessionsAndAudits(): void
    {
        $ops = $this->fx->operations();
        $session = $this->fx->signIn($ops, 'admin');
        $this->fx->system->db()->write(fn () => $this->fx->users->setPassword($this->fx->person('admin')->id, $this->fx->hasher->hash(CoreFixture::PASSWORD), true));
        $before = $this->fx->users->passwordHash($this->fx->person('admin')->id);

        [$code, $output] = $this->reset(['ADMIN'], self::NEW . "\n" . self::NEW . "\n");

        self::assertSame(0, $code, $output);
        $after = $this->fx->users->passwordHash($this->fx->person('admin')->id);
        self::assertNotSame($before, $after);
        self::assertTrue(password_verify(self::NEW, $after));
        self::assertFalse($this->fx->person('admin')->mustChangePassword);
        self::assertSame(0, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM sessions'));
        self::assertNull($ops->auth()->resume($session->token()));
        $entry = $ops->audit()->recent($this->fx->actor('admin'), 1)[0];
        self::assertSame([AuditAction::PEOPLE_PASSWORD_SET, null, 'user', $this->fx->person('admin')->key], [$entry->action, $entry->actorUserId, $entry->targetKind, $entry->targetKey]);
        self::assertStringNotContainsString($this->fx->temp->path(), $output);
        self::assertStringNotContainsString(self::NEW, $output);
    }

    public function testClearsTheThrottleAndWarnsAboutAnInactivePerson(): void
    {
        $ops = $this->fx->operations();
        for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
            $ops->auth()->signIn('member', 'wrong horse ' . $i, '192.0.2.' . (50 + $i));
        }
        self::assertSame(SignInOutcome::Throttled, $ops->auth()->signIn('member', CoreFixture::PASSWORD, '192.0.2.60')->outcome);
        $ops->people()->deactivate($this->fx->actor('admin'), $this->fx->person('member')->key);

        [$code, $output] = $this->reset(['member'], self::NEW . "\n" . self::NEW . "\n");

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('must reactivate them before they can sign in', $output);
        $bucket = LoginThrottle::userBucket($this->fx->system, 'member');
        self::assertNull($this->fx->system->db()->value('SELECT 1 FROM login_throttle WHERE bucket = ?', [$bucket]));
        $ops->people()->reactivate($this->fx->actor('admin'), $this->fx->person('member')->key);
        self::assertSame(SignInOutcome::Success, $ops->auth()->signIn('member', self::NEW, '192.0.2.61')->outcome);
    }

    public function testAnActivePersonGetsNoReactivationNote(): void
    {
        [$code, $output] = $this->reset(['member'], self::NEW . "\n" . self::NEW . "\n");

        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('reactivate', $output);
    }

    public function testAnUnknownUserIsRefused(): void
    {
        [$code, $output] = $this->reset(['nobody'], self::NEW . "\n" . self::NEW . "\n");

        self::assertSame(1, $code);
        self::assertStringContainsString('No person has that username.', $output);
        self::assertSame(0, (int) $this->fx->system->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'people.password_set'"));
    }

    public function testAWeakPasswordIsRefused(): void
    {
        $before = $this->fx->users->passwordHash($this->fx->person('member')->id);

        [$code, $output] = $this->reset(['member'], "short\nshort\n");

        self::assertSame(1, $code);
        self::assertStringContainsString('at least 10 characters', $output);
        self::assertSame($before, $this->fx->users->passwordHash($this->fx->person('member')->id));
        self::assertSame(0, (int) $this->fx->system->db()->value("SELECT COUNT(*) FROM audit_log WHERE action = 'people.password_set'"));
    }

    public function testDifferentPasswordsAndMissingInputAreRefused(): void
    {
        $before = $this->fx->users->passwordHash($this->fx->person('member')->id);
        self::assertSame(1, $this->reset(['member'], self::NEW . "\nanother horse 44\n")[0]);
        self::assertSame(1, $this->reset(['member'], self::NEW . "\n")[0]);
        self::assertSame(1, $this->reset([], '')[0]);
        [$code, $output] = $this->reset(['member', '--data-dir=' . $this->fx->temp->path('elsewhere')], self::NEW . "\n" . self::NEW . "\n", false);
        self::assertSame(1, $code);
        self::assertStringNotContainsString($this->fx->temp->path(), $output);
        self::assertSame($before, $this->fx->users->passwordHash($this->fx->person('member')->id));
    }

    public function testThePasswordIsNotTrimmed(): void
    {
        [$code] = $this->reset(['member'], ' ' . self::NEW . " \r\n " . self::NEW . " \r\n");

        self::assertSame(0, $code);
        $hash = $this->fx->users->passwordHash($this->fx->person('member')->id);
        self::assertTrue(password_verify(' ' . self::NEW . ' ', $hash));
        self::assertFalse(password_verify(self::NEW, $hash));
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string} exit code and everything printed
     */
    private function reset(array $arguments, string $input, bool $withDataDir = true): array
    {
        $command = [PHP_BINARY, RepoFiles::path('tools/admin/reset-password.php'), ...$arguments];
        if ($withDataDir) {
            $command[] = '--data-dir=' . $this->fx->data->path();
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
