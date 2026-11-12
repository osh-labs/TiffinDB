<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\System\SystemDatabase;
use TiffinDB\Tests\Support\FixedClock;
use TiffinDB\Tests\Support\SeededRandom;
use TiffinDB\Tests\Support\TempDir;

/** T-AUTH-6: sign-in throttling. */
#[CoversClass(LoginThrottle::class)]
final class LoginThrottleTest extends TestCase
{
    private const ADDRESS = '192.0.2.10';

    private TempDir $temp;
    private FixedClock $clock;
    private SystemDatabase $system;
    private LoginThrottle $throttle;
    private string $user;
    private string $address;

    protected function setUp(): void
    {
        $this->temp = TempDir::create();
        $this->clock = new FixedClock('2026-06-01T12:00:00Z');
        $this->system = SystemDatabase::create(
            new DataDirectory($this->temp->path(), 'data'),
            $this->clock,
            new SeededRandom('tiffindb-throttle-test-seed-1'),
        );
        $this->throttle = new LoginThrottle($this->system, $this->clock);
        $this->user = LoginThrottle::userBucket($this->system, 'examplemember');
        $this->address = LoginThrottle::addressBucket($this->system, self::ADDRESS);
    }

    protected function tearDown(): void
    {
        $this->system->db()->close();
        $this->temp->remove();
    }

    private function failOnce(string $bucket, int $limit = LoginThrottle::USER_LIMIT): void
    {
        $this->system->db()->write(fn () => $this->throttle->failure($bucket, $limit));
    }

    public function testFourFailuresNoWaitThenThirtyDoublingToTheCap(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->failOnce($this->user);
            $this->assertSame(0, $this->throttle->wait([$this->user]), 'After failure ' . $i);
        }
        $this->failOnce($this->user);
        $this->assertSame(30, $this->throttle->wait([$this->user]));
        $this->clock->advance(10);
        $this->assertSame(20, $this->throttle->wait([$this->user]));

        // Each further failure (here counted while still blocked, which the algorithm allows) doubles the wait, up to 900 s.
        foreach ([60, 120, 240, 480, 900, 900, 900] as $wait) {
            $this->failOnce($this->user);
            $this->assertSame($wait, $this->throttle->wait([$this->user]));
        }
    }

    public function testWaitingOutEachBlockKeepsDoublingToTheCap(): void
    {
        // P65: the reset is measured from the last failure, so waiting out each block never resets the bucket.
        for ($i = 1; $i <= 5; $i++) {
            $this->failOnce($this->user);
        }
        $this->assertSame(30, $this->throttle->wait([$this->user]));
        foreach ([60, 120, 240, 480, 900, 900] as $wait) {
            $this->clock->advance($this->throttle->wait([$this->user]));
            $this->assertSame(0, $this->throttle->wait([$this->user]));
            $this->failOnce($this->user);
            $this->assertSame($wait, $this->throttle->wait([$this->user]));
        }
        $this->assertSame(11, $this->system->db()->value('SELECT failures FROM login_throttle WHERE bucket = ?', [$this->user]));
    }

    public function testABucketWithNoFailureForMoreThanFifteenMinutesResets(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->failOnce($this->user);
        }
        $this->clock->advance(LoginThrottle::WINDOW_SECONDS);
        $this->failOnce($this->user);
        $this->assertSame(30, $this->throttle->wait([$this->user]), 'Exactly 900 s after the last failure still counts.');

        $this->clock->advance(LoginThrottle::WINDOW_SECONDS + 1);
        $this->failOnce($this->user);
        $this->assertSame(0, $this->throttle->wait([$this->user]));
        $row = $this->system->db()->one('SELECT failures, window_started_at, last_failure_at FROM login_throttle WHERE bucket = ?', [$this->user]);
        $this->assertSame(['failures' => 1, 'window_started_at' => '2026-06-01T12:30:01Z', 'last_failure_at' => '2026-06-01T12:30:01Z'], $row);
    }

    public function testFailuresSpreadOutButEachWithinFifteenMinutesAccumulate(): void
    {
        // The window start is more than 900 s old, but no gap between failures is.
        for ($i = 1; $i <= 4; $i++) {
            $this->failOnce($this->user);
            $this->clock->advance(800);
        }
        $this->failOnce($this->user);
        $this->assertSame(30, $this->throttle->wait([$this->user]));
        $this->assertSame('2026-06-01T12:00:00Z', $this->system->db()->value('SELECT window_started_at FROM login_throttle WHERE bucket = ?', [$this->user]));
    }

    public function testWithinTheWindowFailuresAccumulate(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->failOnce($this->user);
            $this->clock->advance(200);
        }
        $this->failOnce($this->user);
        $this->assertSame(30, $this->throttle->wait([$this->user]), 'The fifth failure 800 seconds after the first blocks.');
    }

    public function testClear(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failOnce($this->user);
        }
        $this->assertSame(30, $this->throttle->wait([$this->user]));
        $this->system->db()->write(fn () => $this->throttle->clear($this->user));
        $this->assertSame(0, $this->throttle->wait([$this->user]));
        $this->assertSame(0, (int) $this->system->db()->value('SELECT COUNT(*) FROM login_throttle'));
    }

    public function testAddressLimitIsTwenty(): void
    {
        for ($i = 1; $i <= 19; $i++) {
            $this->failOnce($this->address, LoginThrottle::ADDRESS_LIMIT);
        }
        $this->assertSame(0, $this->throttle->wait([$this->address]));
        $this->failOnce($this->address, LoginThrottle::ADDRESS_LIMIT);
        $this->assertSame(30, $this->throttle->wait([$this->address]));
    }

    public function testWaitIsTheLongestBlockAmongTheBuckets(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->clock->advance($this->throttle->wait([$this->user]));
            $this->failOnce($this->user);
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->failOnce($this->address);
        }
        $this->assertSame(60, $this->throttle->wait([$this->user, $this->address]));
        $this->assertSame(30, $this->throttle->wait([$this->address]));
        $this->assertSame(0, $this->throttle->wait([LoginThrottle::addressBucket($this->system, '198.51.100.7')]));
        $this->assertSame(0, $this->throttle->wait([]));
    }

    public function testSetupBucket(): void
    {
        for ($i = 1; $i <= LoginThrottle::SETUP_LIMIT; $i++) {
            $this->failOnce(LoginThrottle::SETUP_BUCKET, LoginThrottle::SETUP_LIMIT);
        }
        $this->assertSame(30, $this->throttle->wait([LoginThrottle::SETUP_BUCKET]));
    }

    public function testStaleRowsAreDeleted(): void
    {
        $this->failOnce($this->user);
        $this->clock->advance(LoginThrottle::STALE_SECONDS + 1);
        $this->failOnce($this->address);
        $buckets = array_column($this->system->db()->all('SELECT bucket FROM login_throttle'), 'bucket');
        $this->assertSame([$this->address], $buckets);
    }

    public function testTheTableHoldsNoRawUsernameOrAddress(): void
    {
        $this->failOnce($this->user);
        $this->failOnce($this->address);
        $this->assertMatchesRegularExpression('/^u:[0-9a-f]{64}\z/', $this->user);
        $this->assertMatchesRegularExpression('/^a:[0-9a-f]{64}\z/', $this->address);
        $this->assertSame('u:' . $this->system->hmac('throttle-user', 'examplemember'), $this->user);
        $dump = json_encode($this->system->db()->all('SELECT * FROM login_throttle'));
        $this->assertStringNotContainsString('examplemember', (string) $dump);
        $this->assertStringNotContainsString(self::ADDRESS, (string) $dump);
        $this->assertNotSame(
            LoginThrottle::userBucket($this->system, 'x'),
            substr_replace(LoginThrottle::addressBucket($this->system, 'x'), 'u', 0, 1),
            'Username and address buckets use different HMAC purposes.',
        );
    }

    public function testWaitForCapsWithoutOverflow(): void
    {
        $this->assertSame(0, LoginThrottle::waitFor(-1));
        $this->assertSame(30, LoginThrottle::waitFor(0));
        $this->assertSame(480, LoginThrottle::waitFor(4));
        $this->assertSame(900, LoginThrottle::waitFor(5));
        $this->assertSame(900, LoginThrottle::waitFor(PHP_INT_MAX));
    }

    public function testChangesNeedTheCallersTransactionAndBucketsAreChecked(): void
    {
        try {
            $this->throttle->failure($this->user, 5);
            $this->fail('failure() ran outside a transaction.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->throttle->clear($this->user);
            $this->fail('clear() ran outside a transaction.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->throttle->wait(['u:examplemember']);
    }
}
