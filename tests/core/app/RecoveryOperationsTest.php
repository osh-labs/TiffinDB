<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\RecoveryOperations;
use TiffinDB\Core\App\RecoveryResult;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SignInOutcome;
use TiffinDB\Core\Auth\ThrottleState;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Install\RecoveryCode;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Tests\Support\CoreFixture;

require_once __DIR__ . '/AppTestCase.php';

/**
 * Admin recovery (M7 spec section 9; T-REC-1 to T-REC-12, T-REC-14) and
 * hostile cases: a code used twice, by two requests racing, a link at the
 * code path, an exhausted attempt limit, and the code never leaking into
 * a log line, an exception message, an audit row, or the system database.
 */
#[CoversClass(RecoveryOperations::class)]
#[CoversClass(RecoveryResult::class)]
final class RecoveryOperationsTest extends AppTestCase
{
    private const NEW = 'a fresh recovery 42';
    private const OTHER = 'some other phrase 7';

    private RecoveryOperations $rec;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rec = $this->ops->recovery();
        $this->file = $this->fx->data->path() . '/' . RecoveryCode::FILE;
    }

    private function code(): string
    {
        $code = RecoveryCode::current($this->fx->data->path(), $this->fx->clock->now());
        self::assertNotNull($code, 'A valid code file exists.');

        return $code;
    }

    private function recover(string $username, string $code, string $password = self::NEW, ?RecoveryOperations $rec = null): RecoveryResult
    {
        return ($rec ?? $this->rec)->recover(true, $username, $code, $password, $password, CoreFixture::ADDRESS);
    }

    private function hashOf(string $username): string
    {
        return $this->fx->users->passwordHash($this->fx->person($username)->id);
    }

    private function sessionCount(string $username): int
    {
        return (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$this->fx->person($username)->id]);
    }

    private function clearThrottle(): void
    {
        $this->fx->system->db()->run('DELETE FROM login_throttle');
    }

    /** @return array<string, list<array<string, mixed>>> the tables recovery may change, as rows */
    private function state(): array
    {
        $db = $this->fx->system->db();

        return [
            'users' => $db->all('SELECT * FROM users ORDER BY id'),
            'sessions' => $db->all('SELECT * FROM sessions ORDER BY token_hash'),
            'recovered' => $db->all('SELECT * FROM audit_log WHERE action = ?', [AuditAction::RECOVERED]),
        ];
    }

    /** @return list<string> */
    private function throttleBuckets(): array
    {
        return array_map(static fn (array $r): string => (string) $r['bucket'], $this->fx->system->db()->all('SELECT bucket FROM login_throttle ORDER BY bucket'));
    }

    // T-REC-1

    public function testStartWritesTheFileOnceAndAgainAfterThirtyMinutes(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $text = (string) file_get_contents($this->file);
        $first = $this->code();
        self::assertSame(RecoveryCode::text($first, $this->fx->clock->now()), $text);
        self::assertMatchesRegularExpression('/^TiffinDB Admin recovery code\n[A-Z2-9]{4}(-[A-Z2-9]{4}){3}\nCreated 2026-03-02T10:00:00Z\nThis code works once, for 30 minutes\. TiffinDB deletes this file when it is used\.\n\z/', $text);
        $entry = $this->lastAudit(AuditAction::RECOVERY_STARTED);
        self::assertSame([null, null, null, null, ['written' => true]], [$entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey, $entry->detail]);

        $this->fx->clock->advance(1799);
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertSame($text, file_get_contents($this->file), 'A valid code is never replaced.');
        self::assertSame(['written' => false], $this->lastAudit(AuditAction::RECOVERY_STARTED)->detail);

        $this->fx->clock->advance(1);
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertNotSame($first, $this->code());
        self::assertSame(['written' => true], $this->lastAudit(AuditAction::RECOVERY_STARTED)->detail);
    }

    // T-REC-2

    public function testTheRightCodeResetsTheAdminPasswordAndEndsEverySession(): void
    {
        $this->fx->signIn($this->ops, 'admin');
        $this->fx->signIn($this->ops, 'admin');
        $this->fx->signIn($this->ops, 'member');
        $id = $this->fx->person('admin')->id;
        $this->fx->system->db()->write(function () use ($id): void {
            $this->fx->users->setPassword($id, $this->hashOf('admin'), true);
        });
        $this->ops->auth()->signIn('admin', 'wrong horse 1', CoreFixture::ADDRESS);
        $this->ops->auth()->signIn('admin', 'wrong horse 1', CoreFixture::ADDRESS);
        $userBucket = LoginThrottle::userBucket($this->fx->system, 'admin');
        self::assertSame(2, $this->throttleState($userBucket)?->failures);
        $this->rec->start(true, CoreFixture::ADDRESS);
        $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS');
        self::assertNotNull($this->throttleState(RecoveryOperations::ATTEMPT_BUCKET));
        $sessionsBefore = (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM sessions');

        $result = $this->recover('admin', $this->code());

        self::assertEquals(new RecoveryResult(RecoveryResult::DONE), $result);
        self::assertTrue($this->fx->hasher->verify(self::NEW, $this->hashOf('admin')));
        self::assertFalse($this->fx->person('admin')->mustChangePassword);
        self::assertSame(0, $this->sessionCount('admin'));
        self::assertSame(1, $this->sessionCount('member'), 'Only the recovered account loses its sessions.');
        self::assertSame($sessionsBefore - 2, (int) $this->fx->system->db()->value('SELECT COUNT(*) FROM sessions'), 'No session is started.');
        self::assertNull($this->throttleState($userBucket));
        self::assertNull($this->throttleState(RecoveryOperations::ATTEMPT_BUCKET));
        self::assertFileDoesNotExist($this->file);
        $entry = $this->lastAudit(AuditAction::RECOVERED);
        self::assertSame([null, null, 'user', $this->admin->userKey, []], [$entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey, $entry->detail]);

        self::assertSame(SignInOutcome::Invalid, $this->ops->auth()->signIn('admin', CoreFixture::PASSWORD, CoreFixture::ADDRESS)->outcome);
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('admin', self::NEW, CoreFixture::ADDRESS)->outcome);
    }

    private function throttleState(string $bucket): ?ThrottleState
    {
        return (new LoginThrottle($this->fx->system, $this->fx->clock))->state($bucket);
    }

    public function testTheCodeWorksOnce(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        self::assertSame(RecoveryResult::DONE, $this->recover('admin', $code)->outcome);
        $hash = $this->hashOf('admin');
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', $code, self::OTHER)->outcome);
        self::assertSame($hash, $this->hashOf('admin'));
    }

    public function testTheUsernameIsNormalisedAsSignInDoes(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertSame(RecoveryResult::DONE, $this->recover('  ADMIN ', $this->code())->outcome);
    }

    // T-REC-3, T-REC-4

    public function testEveryFailureIsTheSameResultChangesNothingAndCostsOneHash(): void
    {
        $this->fx->addPerson('admin2', 'Second Admin', Role::Admin);
        $this->fx->setActive('admin2', false);
        $this->fx->signIn($this->ops, 'admin');
        $this->fx->signIn($this->ops, 'member');
        $cases = [
            'wrong code' => fn (string $code): RecoveryResult => $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS'),
            'unknown username' => fn (string $code): RecoveryResult => $this->recover('nobody', $code),
            'a Member' => fn (string $code): RecoveryResult => $this->recover('member', $code),
            'an inactive Admin' => fn (string $code): RecoveryResult => $this->recover('admin2', $code),
            'empty code' => fn (string $code): RecoveryResult => $this->recover('admin', ''),
            'invalid UTF-8 username' => fn (string $code): RecoveryResult => $this->recover("adm\xffin", $code),
            'expired code' => function (string $code): RecoveryResult {
                $this->fx->clock->advance(RecoveryCode::VALID_SECONDS);

                return $this->recover('admin', $code);
            },
            'no file' => function (string $code): RecoveryResult {
                unlink($this->file);

                return $this->recover('admin', $code);
            },
        ];
        foreach ($cases as $name => $case) {
            $this->fx->clock->set(CoreFixture::START);
            $this->clearThrottle();
            if (is_file($this->file)) {
                unlink($this->file);
            }
            $this->rec->start(true, CoreFixture::ADDRESS);
            $code = $this->code();
            $before = $this->state();
            $hashes = $this->fx->hasher->hashCalls();

            $result = $case($code);

            self::assertEquals(new RecoveryResult(RecoveryResult::FAILED), $result, $name);
            self::assertSame(1, $this->fx->hasher->hashCalls() - $hashes, $name . ': exactly one hash.');
            self::assertSame($before, $this->state(), $name . ': nothing changed.');
            if ($name !== 'no file') {
                self::assertFileExists($this->file, $name . ': one failure keeps the code.');
            }
        }
    }

    // T-REC-5

    public function testFiveFailuresDeleteTheCodeAndTheSixthWaits(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame(RecoveryResult::FAILED, $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS')->outcome);
            self::assertFileExists($this->file, 'After failure ' . $i);
        }
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS')->outcome);
        self::assertFileDoesNotExist($this->file, 'The fifth failure deletes the code.');

        $hash = $this->hashOf('admin');
        $hashes = $this->fx->hasher->hashCalls();
        $result = $this->recover('admin', $code);
        self::assertEquals(new RecoveryResult(RecoveryResult::WAIT, LoginThrottle::FIRST_WAIT), $result);
        self::assertSame($hashes, $this->fx->hasher->hashCalls(), 'A throttled attempt costs no hash.');
        self::assertSame($hash, $this->hashOf('admin'));

        // Even a new, right code waits out the block.
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertSame(RecoveryResult::WAIT, $this->recover('admin', $this->code())->outcome);
        self::assertSame($hash, $this->hashOf('admin'));
        $this->fx->clock->advance(LoginThrottle::FIRST_WAIT);
        self::assertSame(RecoveryResult::DONE, $this->recover('admin', $this->code())->outcome);
    }

    // T-REC-6

    public function testRecoveryFailuresCountOnTheSignInAddressBucket(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        for ($i = 0; $i < LoginThrottle::ADDRESS_LIMIT - 1; $i++) {
            $this->ops->auth()->signIn('nobody' . $i, 'wrong horse 1', CoreFixture::ADDRESS);
        }
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS')->outcome);
        $signIn = $this->ops->auth()->signIn('admin', CoreFixture::PASSWORD, CoreFixture::ADDRESS);
        self::assertSame(SignInOutcome::Throttled, $signIn->outcome, 'The recovery failure was the twentieth on the address.');
        self::assertSame(SignInOutcome::Success, $this->ops->auth()->signIn('admin', CoreFixture::PASSWORD, '192.0.2.99')->outcome);
    }

    public function testSignInFailuresBlockRecoveryFromTheSameAddress(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        for ($i = 0; $i < LoginThrottle::ADDRESS_LIMIT; $i++) {
            $this->ops->auth()->signIn('nobody' . $i, 'wrong horse 1', CoreFixture::ADDRESS);
        }
        $hash = $this->hashOf('admin');
        self::assertSame(RecoveryResult::WAIT, $this->recover('admin', $this->code())->outcome);
        self::assertSame($hash, $this->hashOf('admin'));
        self::assertFileExists($this->file);
        $other = $this->rec->recover(true, 'admin', $this->code(), self::NEW, self::NEW, '192.0.2.99');
        self::assertSame(RecoveryResult::DONE, $other->outcome, 'Another address is not blocked.');
    }

    public function testAnIpv6AddressCountsOnItsPrefix(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $this->rec->recover(true, 'admin', 'ABCD-EFGH-JKMN-PQRS', self::NEW, self::NEW, '2001:db8::1');
        $bucket = LoginThrottle::addressBucket($this->fx->system, '2001:db8::2');
        self::assertSame(1, $this->throttleState($bucket)?->failures);
    }

    // T-REC-7

    public function testPasswordProblemsAreRefusedBeforeAnythingIsCounted(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        $this->clearThrottle();
        $hashes = $this->fx->hasher->hashCalls();
        $before = $this->state();

        self::assertSame(
            [['password-again', 'problem.password.mismatch', []]],
            self::problems(fn () => $this->rec->recover(true, 'admin', $code, self::NEW, self::NEW . 'x', CoreFixture::ADDRESS)),
        );
        self::assertSame(
            [['password-again', 'problem.password.mismatch', []], ['password', 'problem.password.too_short', ['min' => 10]]],
            self::problems(fn () => $this->rec->recover(true, 'admin', $code, 'short', 'shorter', CoreFixture::ADDRESS)),
        );
        self::assertSame(
            [['password', 'problem.password.same_as_username', []]],
            self::problems(fn () => $this->rec->recover(true, 'administrator', $code, 'Administrator', 'Administrator', CoreFixture::ADDRESS)),
        );
        self::assertSame(
            [['password', 'problem.text.control', []]],
            self::problems(fn () => $this->rec->recover(true, 'admin', $code, "nul byte \0 here", "nul byte \0 here", CoreFixture::ADDRESS)),
        );
        self::assertSame(
            [['password', 'problem.password.too_long', []]],
            self::problems(fn () => $this->rec->recover(true, 'admin', $code, str_repeat('a', 73), str_repeat('a', 73), CoreFixture::ADDRESS)),
        );

        self::assertSame([], $this->throttleBuckets(), 'No failure is counted.');
        self::assertSame($hashes, $this->fx->hasher->hashCalls());
        self::assertSame($before, $this->state());
        self::assertSame($code, $this->code(), 'The code is kept.');
    }

    // T-REC-8

    public function testTurnedOffIsNotFoundAndWritesNothing(): void
    {
        $this->assertThrows(NotFound::class, fn () => $this->rec->start(false, CoreFixture::ADDRESS));
        self::assertFileDoesNotExist($this->file);
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        $this->clearThrottle();
        $before = $this->state();
        $this->assertThrows(NotFound::class, fn () => $this->rec->recover(false, 'admin', $code, self::NEW, self::NEW, CoreFixture::ADDRESS));
        $this->assertThrows(NotFound::class, fn () => $this->rec->recover(false, 'admin', $code, 'short', 'other', CoreFixture::ADDRESS));
        self::assertSame($before, $this->state());
        self::assertSame([], $this->throttleBuckets());
        self::assertSame($code, $this->code());
        self::assertCount(1, array_filter($this->auditActions(), static fn (string $a): bool => $a === AuditAction::RECOVERY_STARTED));
    }

    // T-REC-9

    public function testALinkAtTheCodePathIsNeitherFollowedNorAccepted(): void
    {
        $this->captureLog();
        $target = $this->fx->temp->path('planted-code.txt');
        file_put_contents($target, RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', $this->fx->clock->now()));
        $planted = (string) file_get_contents($target);
        symlink($target, $this->file);

        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertTrue(is_link($this->file));
        self::assertSame($planted, file_get_contents($target), 'Nothing is written through the link.');
        self::assertSame(['written' => false], $this->lastAudit(AuditAction::RECOVERY_STARTED)->detail);

        $hash = $this->hashOf('admin');
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS')->outcome);
        self::assertSame($hash, $this->hashOf('admin'));
        self::assertStringNotContainsString($this->fx->data->path(), $this->errorLog());
    }

    public function testADanglingLinkIsNotWrittenThrough(): void
    {
        $this->captureLog();
        $target = $this->fx->temp->path('outside.txt');
        symlink($target, $this->file);
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertFileDoesNotExist($target);
    }

    // T-REC-10

    public function testTheCodeNeverReachesALogAnExceptionAnAuditRowOrTheDatabase(): void
    {
        $this->captureLog();
        $messages = [];
        $codes = [];
        $run = function (callable $work) use (&$messages): void {
            try {
                $work();
            } catch (\Throwable $error) {
                $messages[] = $error->getMessage();
            }
        };
        $this->rec->start(true, CoreFixture::ADDRESS);
        $codes[] = $this->code();
        $this->rec->start(true, CoreFixture::ADDRESS);
        $run(fn () => $this->rec->recover(true, 'admin', $codes[0], self::NEW, 'mismatch phrase 1', CoreFixture::ADDRESS));
        $run(fn () => $this->rec->recover(false, 'admin', $codes[0], self::NEW, self::NEW, CoreFixture::ADDRESS));
        $run(fn () => $this->rec->start(false, CoreFixture::ADDRESS));
        $run(fn () => $this->recover('member', $codes[0]));
        $run(fn () => $this->recover('nobody', $codes[0]));
        $run(fn () => $this->recover('admin', strtolower($codes[0])));
        $this->fx->clock->advance(RecoveryCode::VALID_SECONDS);
        $run(fn () => $this->recover('admin', $codes[0]));
        $this->rec->start(true, CoreFixture::ADDRESS);
        $codes[] = $this->code();
        for ($i = 0; $i < 6; $i++) {
            $run(fn () => $this->recover('admin', $codes[1] . 'X'));
        }
        $this->fx->clock->advance(3600);
        $this->rec->start(true, CoreFixture::ADDRESS);
        $codes[] = $this->code();
        $run(fn () => $this->recover('admin', $codes[2]));
        symlink($this->fx->temp->path('nowhere'), $this->file);
        $this->rec->start(true, CoreFixture::ADDRESS);
        $run(fn () => $this->recover('admin', $codes[2]));

        $haystacks = [
            'log' => $this->errorLog(),
            'exceptions' => implode("\n", $messages),
            'audit' => json_encode($this->auditEntries(), JSON_THROW_ON_ERROR),
            'database' => json_encode($this->fx->systemRows(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
        self::assertNotSame('', $haystacks['log']);
        self::assertNotSame('', $haystacks['exceptions']);
        foreach ($codes as $code) {
            foreach ([$code, str_replace('-', '', $code), strtolower($code)] as $form) {
                foreach ($haystacks as $where => $text) {
                    self::assertStringNotContainsString($form, $text, $where);
                }
            }
        }
        foreach ($haystacks as $where => $text) {
            self::assertStringNotContainsString(self::NEW, $text, $where);
            self::assertStringNotContainsString($this->fx->data->path(), $text, $where . ' holds no path.');
            self::assertStringNotContainsString(RecoveryCode::FILE, $text, $where . ' holds no file name.');
        }
    }

    // T-REC-11

    public function testTheSixthStartInAWindowWritesNothing(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->rec->start(true, CoreFixture::ADDRESS);
            self::assertFileExists($this->file, 'Start ' . $i);
            unlink($this->file);
        }
        $audits = count($this->auditActions());
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertFileDoesNotExist($this->file);
        self::assertSame($audits, count($this->auditActions()), 'A throttled start is not audited.');
        $this->fx->clock->advance(LoginThrottle::FIRST_WAIT);
        $this->rec->start(true, CoreFixture::ADDRESS);
        self::assertFileExists($this->file, 'Starts work again once the wait is over.');
    }

    public function testStartsAreOneBucketForEveryAddress(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->rec->start(true, '192.0.2.' . $i);
            unlink($this->file);
        }
        $this->rec->start(true, '198.51.100.7');
        self::assertFileDoesNotExist($this->file);
    }

    // T-REC-12

    public function testTheCodeMayBeTypedWithSpacesDashesAndLowerCase(): void
    {
        foreach ([
            static fn (string $c): string => strtolower($c),
            static fn (string $c): string => ' ' . str_replace('-', ' ', $c) . "\t",
            static fn (string $c): string => str_replace('-', '', $c),
            static fn (string $c): string => implode('-', str_split(str_replace('-', '', $c), 2)),
        ] as $i => $typed) {
            $this->fx->clock->advance(RecoveryCode::VALID_SECONDS);
            $this->rec->start(true, CoreFixture::ADDRESS);
            self::assertSame(RecoveryResult::DONE, $this->recover('admin', $typed($this->code()))->outcome, 'Form ' . $i);
        }
        $this->fx->clock->advance(RecoveryCode::VALID_SECONDS);
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', substr($code, 0, -1))->outcome, 'A prefix is not the code.');
        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', $code . 'A')->outcome, 'A longer text is not the code.');
    }

    // T-REC-14

    public function testAnOversizedOrNonUtf8FileIsNoCodeAndStartReplacesIt(): void
    {
        foreach ([
            RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', $this->fx->clock->now()) . str_repeat(' ', RecoveryCode::MAX_FILE_BYTES),
            str_replace('recovery', "recov\xc3ry", RecoveryCode::text('ABCD-EFGH-JKMN-PQRS', $this->fx->clock->now())),
        ] as $i => $contents) {
            $this->clearThrottle();
            if (file_exists($this->file)) {
                unlink($this->file);
            }
            file_put_contents($this->file, $contents);
            self::assertSame(RecoveryResult::FAILED, $this->recover('admin', 'ABCD-EFGH-JKMN-PQRS')->outcome, 'File ' . $i);
            $this->rec->start(true, CoreFixture::ADDRESS);
            self::assertNotSame($contents, file_get_contents($this->file));
            self::assertNotSame('ABCD-EFGH-JKMN-PQRS', $this->code());
        }
    }

    // Transactions (section 9.3 step 5)

    public function testARequestThatUsedTheCodeWhileThisOneHashedWinsAndThisOneFails(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        $other = $this->fx->operations(null, $this->fx->openSystem())->recovery();
        $raced = false;
        $ops = $this->fx->operations(null, null, function (string $point) use (&$raced, $other, $code): void {
            if ($point === 'recovery.hashed' && !$raced) {
                $raced = true;
                self::assertSame(RecoveryResult::DONE, $this->recover('admin', $code, self::OTHER, $other)->outcome);
            }
        });

        self::assertSame(RecoveryResult::FAILED, $this->recover('admin', $code, self::NEW, $ops->recovery())->outcome);
        self::assertTrue($raced);
        self::assertTrue($this->fx->hasher->verify(self::OTHER, $this->hashOf('admin')), 'The first to commit keeps its password.');
        self::assertCount(1, array_filter($this->auditActions(), static fn (string $a): bool => $a === AuditAction::RECOVERED));
    }

    public function testABlockSetWhileThisRequestHashedIsHonoured(): void
    {
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        $second = $this->fx->openSystem();
        $ops = $this->fx->operations(null, null, function (string $point) use ($second): void {
            if ($point === 'recovery.hashed') {
                self::assertFalse($this->fx->system->db()->inTransaction(), 'The hash is outside any write.');
                $throttle = new LoginThrottle($second, $this->fx->clock);
                $second->db()->write(static function () use ($throttle): void {
                    for ($i = 0; $i < RecoveryOperations::LIMIT; $i++) {
                        $throttle->failure(RecoveryOperations::ATTEMPT_BUCKET, RecoveryOperations::LIMIT);
                    }
                });
            }
        });
        $hash = $this->hashOf('admin');

        self::assertEquals(new RecoveryResult(RecoveryResult::WAIT, LoginThrottle::FIRST_WAIT), $this->recover('admin', $code, self::NEW, $ops->recovery()));
        self::assertSame($hash, $this->hashOf('admin'));
        self::assertSame($code, $this->code(), 'Nothing changed, the code included.');
    }

    public function testACodeFileThatCannotBeRemovedIsLoggedAndTheRecoveryStillCompletes(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions do not stop the superuser from removing the file.');
        }
        $this->captureLog();
        $this->rec->start(true, CoreFixture::ADDRESS);
        $code = $this->code();
        $data = $this->fx->data->path();
        chmod($data, 0550);
        try {
            self::assertSame(RecoveryResult::DONE, $this->recover('admin', $code)->outcome);
        } finally {
            chmod($data, 0750);
        }
        self::assertTrue($this->fx->hasher->verify(self::NEW, $this->hashOf('admin')));
        self::assertStringContainsString('TiffinDB: the recovery code file could not be removed; delete it by hand.', $this->errorLog());
        self::assertStringNotContainsString($code, $this->errorLog());
        self::assertStringNotContainsString($data, $this->errorLog());
    }

    public function testOperationsGivesOneRecoveryInstance(): void
    {
        self::assertSame($this->ops->recovery(), $this->ops->recovery());
    }

    public function testAnUnknownOutcomeIsRefused(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, static fn () => new RecoveryResult('maybe'));
        self::assertSame('problem.recovery.failed', RecoveryResult::FAILED_KEY);
    }
}
