<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use DateTimeImmutable;
use LogicException;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\Authenticator;
use TiffinDB\Core\Auth\Csrf;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;
use TiffinDB\Core\Install\LoopbackHttpProbe;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\SecureRandom;
use TiffinDB\Core\Storage\SystemClock;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;

/**
 * The web layer's composition root (ADR 0012 decision 1 as amended by P58;
 * M1 spec part 2 section 2.8): the one web class that may construct core
 * objects. Everything is built lazily, at most once per request, and
 * dropped by close(), so one instance can serve several requests in a row
 * (the end-to-end tests do) without carrying state from one to the next.
 *
 * The kernel's session methods are implemented over Core\Auth
 * (Authenticator, Sessions, Users), never over Core\App. Every write to
 * the system database runs inside SystemDatabase::write() (P51, P66).
 *
 * The installer opens its own connection to the system database; it has
 * no close method and is released with the instance.
 */
final class CoreServices implements Services
{
    private ?Clock $clockInstance;
    private ?Random $randomInstance;
    private ?PasswordHasher $hasherInstance;

    private ?Installer $installerInstance = null;
    private ?InstallState $state = null;
    private ?DataDirectory $data = null;
    private ?SystemDatabase $system = null;
    private ?Sessions $sessions = null;
    private ?Users $users = null;
    private ?Authenticator $authenticator = null;
    private ?Operations $operationsInstance = null;

    /**
     * The three optional arguments are test seams; production passes none.
     *
     * @param array<string, mixed> $config The merged configuration (config/app.php and config/local.php).
     */
    public function __construct(
        private readonly array $config,
        private readonly string $appRoot,
        ?Clock $clock = null,
        ?Random $random = null,
        ?PasswordHasher $hasher = null,
    ) {
        $this->clockInstance = $clock;
        $this->randomInstance = $random;
        $this->hasherInstance = $hasher;
    }

    public function installState(): InstallState
    {
        // Installer::state() may remove a leftover setup code, so it runs
        // once per request.
        return $this->state ??= $this->installerObject()->state();
    }

    public function installer(): Installer
    {
        if ($this->installState() === InstallState::Installed) {
            throw new LogicException('The installer is not available once installed.');
        }

        return $this->installerObject();
    }

    public function operations(): Operations
    {
        if ($this->installState() !== InstallState::Installed) {
            throw new LogicException('The core operations are not available before installation.');
        }

        return $this->operationsInstance ??= new Operations(
            $this->system(),
            $this->data(),
            $this->clock(),
            $this->random(),
            $this->hasher(),
            $this->version(),
        );
    }

    public function probe(): HttpProbe
    {
        return new LoopbackHttpProbe();
    }

    public function resume(#[\SensitiveParameter] string $token): ?SignedIn
    {
        if ($this->installState() !== InstallState::Installed || !Sessions::wellFormed($token)) {
            return null;
        }

        return $this->authenticator()->resume($token);
    }

    public function endSession(#[\SensitiveParameter] string $token): void
    {
        if ($this->installState() !== InstallState::Installed || !Sessions::wellFormed($token)) {
            return;
        }
        $sessions = $this->sessions();
        $this->system()->db()->write(static function () use ($sessions, $token): void {
            $sessions->end($token);
        });
    }

    public function takeFlash(#[\SensitiveParameter] string $token): ?array
    {
        if ($this->installState() !== InstallState::Installed || !Sessions::wellFormed($token)) {
            return null;
        }
        $sessions = $this->sessions();
        $db = $this->system()->db();
        // P69 style (P79): a plain read first, so that a signed-in page with
        // no notice takes no write lock; Sessions::takeFlash checks again
        // inside the write.
        $pending = $db->value(
            'SELECT 1 FROM sessions WHERE token_hash = ? AND flash IS NOT NULL',
            [Sessions::hashToken($token)],
        );
        if ($pending === null) {
            return null;
        }

        return $db->write(static fn (): ?array => $sessions->takeFlash($token));
    }

    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void
    {
        if ($this->installState() !== InstallState::Installed) {
            throw new LogicException('There are no sessions before installation.');
        }
        $sessions = $this->sessions();
        $this->system()->db()->write(static function () use ($sessions, $token, $key, $params): void {
            $sessions->setFlash($token, $key, $params);
        });
    }

    public function userSettings(int $userId): array
    {
        return $this->users()->settings($userId);
    }

    public function newCsrfToken(): string
    {
        return Csrf::generate($this->random());
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock()->now();
    }

    public function close(): void
    {
        try {
            $this->operationsInstance?->close();
        } finally {
            $this->system?->db()->close();
            $this->operationsInstance = null;
            $this->authenticator = null;
            $this->users = null;
            $this->sessions = null;
            $this->system = null;
            $this->data = null;
            $this->state = null;
            $this->installerInstance = null;
        }
    }

    private function installerObject(): Installer
    {
        return $this->installerInstance ??= new Installer(
            $this->appRoot,
            $this->dataDir(),
            $this->clock(),
            $this->random(),
            $this->hasher(),
            $this->version(),
        );
    }

    private function authenticator(): Authenticator
    {
        if ($this->authenticator === null) {
            $system = $this->system();
            $this->authenticator = new Authenticator(
                $system,
                $this->users(),
                $this->sessions(),
                new LoginThrottle($system, $this->clock()),
                $this->hasher(),
                new AuditLog($system, $this->clock()),
            );
        }

        return $this->authenticator;
    }

    private function sessions(): Sessions
    {
        return $this->sessions ??= new Sessions($this->system(), $this->clock(), $this->random());
    }

    private function users(): Users
    {
        return $this->users ??= new Users($this->system(), new Keys($this->random()), $this->clock());
    }

    private function system(): SystemDatabase
    {
        return $this->system ??= SystemDatabase::open($this->data());
    }

    private function data(): DataDirectory
    {
        if ($this->data === null) {
            $data = DataDirectory::locate($this->appRoot, $this->dataDir());
            if ($data === null) {
                throw new LogicException('The data folder cannot be found.');
            }
            $this->data = $data;
        }

        return $this->data;
    }

    private function clock(): Clock
    {
        return $this->clockInstance ??= new SystemClock();
    }

    private function random(): Random
    {
        return $this->randomInstance ??= new SecureRandom();
    }

    private function hasher(): PasswordHasher
    {
        return $this->hasherInstance ??= new PasswordHasher();
    }

    private function dataDir(): ?string
    {
        $dir = $this->config['data_dir'] ?? null;

        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    private function version(): string
    {
        $version = $this->config['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new LogicException('Configuration: version must be a non-empty string.');
        }

        return $version;
    }
}
