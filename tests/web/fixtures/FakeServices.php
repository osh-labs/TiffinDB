<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 *
 * Test fixture: a scripted Services for the kernel (M1 spec part 2 section
 * 2.8). Install state, sessions, flash notices, and settings are set by the
 * test; every call that matters is recorded. operations(), installer(),
 * and probe() throw unless a test supplies an object. Synthetic data only.
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Web\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Throwable;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\SessionRecord;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Web\Services;

final class FakeServices implements Services
{
    /** A well-formed session token (43 base64url characters), synthetic. */
    public const TOKEN = 'tokenAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    public const OTHER_TOKEN = 'tokenBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
    public const SESSION_CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    public const NEW_CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    public const NOW = '2026-11-03T10:00:00Z';

    /** @var array<string, SignedIn> token => SignedIn */
    public array $sessions = [];

    /** @var array<string, array{key: string, params: array<string, string|int>}> */
    public array $flash = [];

    /** @var array<int, array<string, mixed>> user id => settings row */
    public array $settings = [];

    /** @var list<string> Calls in order, as "method" or "method:argument". */
    public array $calls = [];

    public ?Throwable $installStateThrows = null;
    public ?Throwable $closeThrows = null;
    public ?Operations $operations = null;
    public ?Installer $installer = null;
    public ?HttpProbe $probe = null;
    public int $closed = 0;
    private bool $stateRechecked = false;

    public function __construct(public InstallState $state = InstallState::Installed)
    {
    }

    /** A person signed in with this token (a Member unless $admin). */
    public static function signedIn(bool $admin = false, bool $mustChange = false, string $name = 'Example Member'): SignedIn
    {
        return new SignedIn(
            new Actor($admin ? 1 : 2, $admin ? 'ADMINKEY0001' : 'MEMBERKEY001', $admin ? Role::Admin : Role::Member, $name),
            new SessionRecord(str_repeat('0', 64), $admin ? 1 : 2, self::SESSION_CSRF, '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'),
            $mustChange,
        );
    }

    public function withSession(SignedIn $signedIn, string $token = self::TOKEN): self
    {
        $this->sessions[$token] = $signedIn;

        return $this;
    }

    public function installState(): InstallState
    {
        $this->calls[] = 'installState';
        if ($this->installStateThrows !== null) {
            throw $this->installStateThrows;
        }

        return $this->state;
    }

    public function installer(): Installer
    {
        return $this->installer ?? throw new LogicException('FakeServices has no installer.');
    }

    public function operations(): Operations
    {
        return $this->operations ?? throw new LogicException('FakeServices has no operations.');
    }

    public function probe(): HttpProbe
    {
        return $this->probe ?? throw new LogicException('FakeServices has no probe.');
    }

    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
    {
        $this->calls[] = 'resume';

        return $this->sessions[$token] ?? null;
    }

    public function endSession(#[\SensitiveParameter] string $token): void
    {
        $this->calls[] = 'endSession:' . $token;
        unset($this->sessions[$token]);
    }

    public function takeFlash(#[\SensitiveParameter] string $token): ?array
    {
        $this->calls[] = 'takeFlash';
        if ($this->state !== InstallState::Installed) {
            return null;
        }
        $flash = $this->flash[$token] ?? null;
        unset($this->flash[$token]);

        return $flash;
    }

    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void
    {
        $this->calls[] = 'setFlash:' . $key;
        // As CoreServices: no sessions before installation. A request that
        // finished the installation is seen through the supplied installer,
        // checked again once.
        if ($this->state !== InstallState::Installed && $this->installer !== null && !$this->stateRechecked) {
            $this->stateRechecked = true;
            $this->state = $this->installer->state();
        }
        if ($this->state !== InstallState::Installed) {
            throw new LogicException('There are no sessions before installation.');
        }
        $this->flash[$token] = ['key' => $key, 'params' => $params];
    }

    public function userSettings(int $userId): array
    {
        return $this->settings[$userId] ?? [];
    }

    public function newCsrfToken(): string
    {
        $this->calls[] = 'newCsrfToken';

        return self::NEW_CSRF;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    public function close(): void
    {
        $this->calls[] = 'close';
        $this->closed++;
        if ($this->closeThrows !== null) {
            throw $this->closeThrows;
        }
    }
}
