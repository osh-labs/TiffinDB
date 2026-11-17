<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Authenticator;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\ProbeResponse;
use TiffinDB\Core\Install\ProbeTarget;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-INS-7 (first Admin) and T-INS-9 (everything refused once installed). */
#[CoversClass(Installer::class)]
final class FirstAdminTest extends TestCase
{
    private const PASSWORD = 'example password 1';
    private const ADDRESS = '192.0.2.20';

    private TempDir $temp;
    private FixedClock $clock;
    private Installer $installer;
    private string $data;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        mkdir($this->temp->path('www/app/public'), 0755, true);
        $this->clock = new FixedClock('2026-03-01T10:00:00Z');
        $this->installer = $this->newInstaller();
        $this->data = $this->temp->path('www/tiffindb-data');
        $probe = new class implements HttpProbe {
            public function get(ProbeTarget $target, string $path): ?ProbeResponse
            {
                throw new \LogicException('No probe outside the document root.');
            }
        };
        $this->assertSame('safe', $this->installer->prepareDataFolder($this->temp->path('www/app/public'), null, $probe)->outcome);
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    private function newInstaller(): Installer
    {
        return new Installer(
            $this->temp->path('www/app'),
            null,
            $this->clock,
            new SeededRandom('tiffindb-first-admin-seed-1'),
            new PasswordHasher(['bcrypt' => 4]),
            '0.1.0-test',
        );
    }

    private function code(): string
    {
        return trim((string) file_get_contents($this->data . '/setup-code.txt'));
    }

    private function system(): SystemDatabase
    {
        return SystemDatabase::open(new DataDirectory($this->data, '../tiffindb-data'));
    }

    /** @return list<array{?string, string, array<string, string|int>}> */
    private function problemsOf(\Closure $call): array
    {
        try {
            $call();
        } catch (ValidationFailed $failed) {
            return array_map(static fn (Problem $p): array => [$p->field, $p->key, $p->params], $failed->problems);
        }
        $this->fail('Expected ValidationFailed.');
    }

    private function attempt(string $code): \Closure
    {
        return fn () => $this->installer->createFirstAdmin($code, 'Example Admin', 'admin', self::PASSWORD, self::ADDRESS);
    }

    public function testWrongCodeAndThenThrottled(): void
    {
        for ($i = 1; $i <= LoginThrottle::SETUP_LIMIT; $i++) {
            $this->assertSame([['setup_code', 'problem.setup_code.wrong', []]], $this->problemsOf($this->attempt('AAAA-AAAA-AAAA-AAAA')), "Attempt {$i}");
        }

        $this->assertSame([['setup_code', 'problem.throttled', ['seconds' => 30]]], $this->problemsOf($this->attempt($this->code())));
        $this->clock->advance(10);
        $this->assertSame([['setup_code', 'problem.throttled', ['seconds' => 20]]], $this->problemsOf($this->attempt('AAAA-AAAA-AAAA-AAAA')));
        $this->assertSame(InstallState::NeedsAdmin, $this->installer->state());

        $this->clock->advance(20);
        $this->installer->createFirstAdmin($this->code(), 'Example Admin', 'admin', self::PASSWORD, self::ADDRESS);
        $this->assertSame(InstallState::Installed, $this->installer->state());
    }

    public function testThrottledAttemptsAreNotCounted(): void
    {
        for ($i = 0; $i < LoginThrottle::SETUP_LIMIT; $i++) {
            $this->problemsOf($this->attempt('wrong'));
        }
        $this->problemsOf($this->attempt('wrong'));
        $this->problemsOf($this->attempt('wrong'));

        $state = (new LoginThrottle($this->system(), $this->clock))->state(LoginThrottle::SETUP_BUCKET);
        $this->assertSame(LoginThrottle::SETUP_LIMIT, $state?->failures);
    }

    public function testAllFieldProblemsTogether(): void
    {
        $problems = $this->problemsOf(fn () => $this->installer->createFirstAdmin('wrong', '  ', 'a!', 'short', self::ADDRESS));

        $this->assertSame(
            ['setup_code', 'display_name', 'username', 'password'],
            array_map(static fn (array $p): ?string => $p[0], $problems),
        );
        $this->assertSame('problem.setup_code.wrong', $problems[0][1]);
        $this->assertSame('problem.name.empty', $problems[1][1]);
        $this->assertSame('problem.username.invalid', $problems[2][1]);
        $this->assertSame('problem.password.too_short', $problems[3][1]);
    }

    public function testFieldProblemsWithTheRightCodeAreNotCounted(): void
    {
        $problems = $this->problemsOf(fn () => $this->installer->createFirstAdmin($this->code(), 'Example Admin', 'admin', 'admin', self::ADDRESS));

        $this->assertSame(['password', 'password'], array_map(static fn (array $p): ?string => $p[0], $problems));
        $this->assertNull((new LoginThrottle($this->system(), $this->clock))->state(LoginThrottle::SETUP_BUCKET));
        $this->assertSame(0, (int) $this->system()->db()->value('SELECT COUNT(*) FROM users'));
    }

    public function testSuccessWithACodeTypedInLowerCaseWithSpaces(): void
    {
        $typed = ' ' . strtolower(str_replace('-', ' ', $this->code())) . ' ';

        $session = $this->installer->createFirstAdmin($typed, ' Example Admin ', 'Admin', self::PASSWORD, self::ADDRESS);

        $this->assertSame(InstallState::Installed, $this->installer->state());
        $this->assertSame(InstallState::Installed, $this->newInstaller()->state());
        $this->assertFileDoesNotExist($this->data . '/setup-code.txt');

        $system = $this->system();
        $this->assertSame('2026-03-01T10:00:00Z', $system->meta(SystemDatabase::META_INSTALLED_AT));
        $this->assertSame('0.1.0-test', $system->meta(SystemDatabase::META_INSTALLED_WITH));
        $random = new SeededRandom('tiffindb-first-admin-seed-2');
        $users = new Users($system, new Keys($random), $this->clock);
        $admin = $users->byUsername('admin');
        $this->assertNotNull($admin);
        $this->assertSame('Admin', $admin->username);
        $this->assertSame('Example Admin', $admin->displayName);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->active);
        $this->assertFalse($admin->mustChangePassword);
        $this->assertTrue((new PasswordHasher(['bcrypt' => 4]))->verify(self::PASSWORD, $users->passwordHash($admin->id)));

        $auth = new Authenticator(
            $system,
            $users,
            new Sessions($system, $this->clock, $random),
            new LoginThrottle($system, $this->clock),
            new PasswordHasher(['bcrypt' => 4]),
            new AuditLog($system, $this->clock),
        );
        $signedIn = $auth->resume($session->token());
        $this->assertNotNull($signedIn);
        $this->assertSame($admin->id, $signedIn->actor->userId);
        $this->assertTrue($signedIn->actor->isAdmin());

        $entries = (new AuditLog($system, $this->clock))->recent(10);
        $this->assertCount(1, $entries);
        $this->assertSame(AuditAction::SETUP_COMPLETE, $entries[0]->action);
        $this->assertSame($admin->id, $entries[0]->actorUserId);
        $this->assertSame('user', $entries[0]->targetKind);
        $this->assertSame($admin->key, $entries[0]->targetKey);
    }

    public function testAMissingCodeFileRefusesEveryCode(): void
    {
        $code = $this->code();
        unlink($this->data . '/setup-code.txt');

        $this->assertSame([['setup_code', 'problem.setup_code.wrong', []]], $this->problemsOf($this->attempt($code)));
        $this->installer->ensureSetupCode();
        $this->assertNotSame('', $this->code());
    }

    public function testAnEmptyCodeFileRefusesAnEmptyCode(): void
    {
        file_put_contents($this->data . '/setup-code.txt', "\n");

        $this->assertSame([['setup_code', 'problem.setup_code.wrong', []]], $this->problemsOf($this->attempt('')));
    }

    public function testEveryMethodButStateIsRefusedOnceInstalled(): void
    {
        $this->installer->createFirstAdmin($this->code(), 'Example Admin', 'admin', self::PASSWORD, self::ADDRESS);

        foreach ([$this->installer, $this->newInstaller()] as $installer) {
            $this->assertSame(InstallState::Installed, $installer->state());
            $calls = [
                'requirements' => static fn () => $installer->requirements(true, null),
                'prepareDataFolder' => static fn () => $installer->prepareDataFolder(null, null, new class implements HttpProbe {
                    public function get(ProbeTarget $target, string $path): ?ProbeResponse
                    {
                        return null;
                    }
                }),
                'setupCodeLocation' => static fn () => $installer->setupCodeLocation(),
                'ensureSetupCode' => static fn () => $installer->ensureSetupCode(),
                'createFirstAdmin' => static fn () => $installer->createFirstAdmin('x', 'Second Admin', 'admin2', self::PASSWORD, self::ADDRESS),
            ];
            foreach ($calls as $name => $call) {
                try {
                    $call();
                    $this->fail($name . ' should throw once installed.');
                } catch (\LogicException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertFileDoesNotExist($this->data . '/setup-code.txt', 'ensureSetupCode wrote nothing.');
        $this->assertSame(1, (int) $this->system()->db()->value('SELECT COUNT(*) FROM users'));
    }

    public function testAnExistingUserIsRefusedEvenWithoutInstalledAt(): void
    {
        $system = $this->system();
        $hash = (new PasswordHasher(['bcrypt' => 4]))->hash(self::PASSWORD);
        $system->db()->write(function () use ($system, $hash): void {
            (new Users($system, new Keys(new SeededRandom('tiffindb-first-admin-seed-3')), $this->clock))
                ->create('someone', 'Example Person', Role::Member, $hash, false);
        });

        $this->expectException(\LogicException::class);
        $this->installer->createFirstAdmin($this->code(), 'Example Admin', 'admin', self::PASSWORD, self::ADDRESS);
    }
}
