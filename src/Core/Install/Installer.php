<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\DisplayNames;
use TiffinDB\Core\Auth\IssuedSession;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\PasswordPolicy;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Usernames;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\ErrorTrap;
use TiffinDB\Core\Storage\Fs;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\Sqlite;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\SystemDatabase;

/**
 * The installer's logic (M1 spec section 10; screens in
 * `docs/design/m1/installer.md`).
 *
 * Once installed, every method other than state() throws \LogicException.
 * prepareDataFolder() runs only in NeedsDataFolder; ensureSetupCode(),
 * setupCodeLocation(), and createFirstAdmin() only in NeedsAdmin.
 *
 * Every write to the system database runs inside SystemDatabase::write()
 * (P51, P66); the setup throttle is checked inside the same transaction
 * that counts a wrong code.
 *
 * Test seam: $createSystem replaces SystemDatabase::create (T-INS-3).
 */
final class Installer
{
    private ?SystemDatabase $system = null;
    private readonly \Closure $createSystem;

    /** @param (\Closure(DataDirectory, Clock, Random): SystemDatabase)|null $createSystem */
    public function __construct(
        private readonly string $appRoot,
        private readonly ?string $configuredDataDir,
        private readonly Clock $clock,
        private readonly Random $random,
        private readonly PasswordHasher $hasher,
        private readonly string $appVersion,
        ?\Closure $createSystem = null,
    ) {
        $this->createSystem = $createSystem ?? static fn (DataDirectory $data, Clock $clock, Random $random): SystemDatabase
            => SystemDatabase::create($data, $clock, $random);
    }

    public function state(): InstallState
    {
        $system = $this->system();
        if ($system === null) {
            return InstallState::NeedsDataFolder;
        }

        return $system->isInstalled() ? InstallState::Installed : InstallState::NeedsAdmin;
    }

    /** The requirement check (section 10.2); $environment is the test seam. */
    public function requirements(bool $secure, ?string $documentRoot, ?RequirementEnvironment $environment = null): RequirementReport
    {
        $this->refuseWhenInstalled();
        $env = $environment ?? new ServerEnvironment();
        $rows = [];

        $version = $env->phpVersion();
        $rows[] = $env->phpVersionId() >= 80300
            ? new Requirement('php', Requirement::OK, 'setup.check.php.ok', ['version' => $version])
            : new Requirement('php', Requirement::FAILED, 'setup.check.php.failed', ['version' => $version]);

        $sqlite = $env->extensionLoaded('pdo_sqlite') ? $env->sqliteVersion() : null;
        if ($sqlite === null) {
            $rows[] = new Requirement('sqlite', Requirement::FAILED, 'setup.check.sqlite.missing');
        } elseif (version_compare($sqlite, Sqlite::MIN_VERSION, '>=')) {
            $rows[] = new Requirement('sqlite', Requirement::OK, 'setup.check.sqlite.ok', ['version' => $sqlite]);
        } else {
            $rows[] = new Requirement('sqlite', Requirement::FAILED, 'setup.check.sqlite.old', ['version' => $sqlite]);
        }

        foreach (['zip', 'xmlreader'] as $extension) {
            $rows[] = $env->extensionLoaded($extension)
                ? new Requirement($extension, Requirement::OK, 'setup.check.' . $extension . '.ok')
                : new Requirement($extension, Requirement::FAILED, 'setup.check.' . $extension . '.failed');
        }

        $rows[] = $env->randomWorks()
            ? new Requirement('random', Requirement::OK, 'setup.check.random.ok')
            : new Requirement('random', Requirement::FAILED, 'setup.check.random.failed');

        $rows[] = $this->hasher->algorithm() === PasswordHasher::ALGORITHM_ARGON2ID
            ? new Requirement('passwords', Requirement::OK, 'setup.check.passwords.argon2id')
            : new Requirement('passwords', Requirement::WARNING, 'setup.check.passwords.bcrypt');

        $rows[] = $secure
            ? new Requirement('https', Requirement::OK, 'setup.check.https.ok')
            : new Requirement('https', Requirement::WARNING, 'setup.check.https.warning');

        $chosen = DataFolder::choose($this->appRoot, $this->configuredDataDir, $documentRoot);
        if ($chosen === null) {
            $first = DataFolder::firstCandidate($this->appRoot, $this->configuredDataDir);
            $rows[] = new Requirement('data', Requirement::FAILED, 'setup.check.data.failed', ['label' => $first->label]);
        } else {
            $key = DataFolder::isOutside($chosen->path, $documentRoot) ? 'setup.check.data.outside' : 'setup.check.data.in-tree';
            $rows[] = new Requirement('data', Requirement::OK, $key, ['label' => $chosen->label]);
        }

        return new RequirementReport($rows);
    }

    /** Chooses, makes, tests, and initialises the data folder (section 10.3). */
    public function prepareDataFolder(?string $documentRoot, ?ProbeTarget $target, HttpProbe $probe): DataFolderResult
    {
        if ($this->state() !== InstallState::NeedsDataFolder) {
            throw new \LogicException('The data folder has already been prepared.');
        }
        $chosen = DataFolder::choose($this->appRoot, $this->configuredDataDir, $documentRoot);
        if ($chosen === null) {
            $first = DataFolder::firstCandidate($this->appRoot, $this->configuredDataDir);

            return new DataFolderResult(DataFolderResult::FAILED, $first->label, $first->inTree, DataFolderResult::NO_PLACE);
        }
        $data = DataDirectory::fromCandidate($chosen);
        $path = $data->path();
        if (file_exists($path) || is_link($path)) {
            if (!is_dir($path) || !DataFolder::holdsOnlyLeftovers($path)) {
                return new DataFolderResult(DataFolderResult::FAILED, $chosen->label, $chosen->inTree, DataFolderResult::NOT_EMPTY);
            }
        } else {
            Fs::makeDir($path);
        }
        foreach (DataFolder::probeFiles($path) as $stale) {
            Fs::remove($path . '/' . $stale);
        }

        $htaccess = $path . '/' . DataFolder::HTACCESS_FILE;
        Fs::remove($htaccess);
        Fs::writeNew($htaccess, DataFolder::HTACCESS);

        if (!DataFolder::isOutside($path, $documentRoot)) {
            $verdict = $target === null
                ? ExposureProbe::INCONCLUSIVE
                : (new ExposureProbe($this->random))->run($path, $this->appRoot, $target, $probe);
            if ($verdict !== ExposureProbe::SAFE) {
                return new DataFolderResult($verdict, $chosen->label, $chosen->inTree);
            }
        }

        // P55: the code file first, so NeedsAdmin (system.sqlite present) always has one.
        $codeFile = $path . '/' . SetupCode::FILE;
        Fs::remove($codeFile);
        Fs::writeNew($codeFile, SetupCode::generate($this->random) . "\n");
        $projects = $path . '/' . DataDirectory::PROJECTS;
        if (!is_dir($projects) || is_link($projects)) {
            Fs::makeDir($projects);
        }
        $this->system = ($this->createSystem)($data, $this->clock, $this->random);

        return new DataFolderResult(DataFolderResult::SAFE, $chosen->label, $chosen->inTree);
    }

    /**
     * Where the setup code is, for messages: the data folder's label and
     * the file name, e.g. `../tiffindb-data/setup-code.txt`; never an
     * absolute path. NeedsAdmin only.
     */
    public function setupCodeLocation(): string
    {
        $data = $this->requireNeedsAdmin();
        if ($data->label() === DataDirectory::CONFIGURED_LABEL) {
            return SetupCode::FILE . ' in ' . DataDirectory::CONFIGURED_LABEL;
        }

        return $data->label() . '/' . SetupCode::FILE;
    }

    /** Writes a new setup code when the file is missing; never replaces one (section 10.3). NeedsAdmin only. */
    public function ensureSetupCode(): void
    {
        $file = $this->requireNeedsAdmin()->path() . '/' . SetupCode::FILE;
        if (file_exists($file) || is_link($file)) {
            return;
        }
        try {
            Fs::writeNew($file, SetupCode::generate($this->random) . "\n");
        } catch (\RuntimeException $error) {
            // A parallel request may have written it first.
            if (!file_exists($file)) {
                throw $error;
            }
        }
    }

    /**
     * Creates the first Admin and signs them in (section 10.5).
     *
     * $clientAddress is accepted for the web layer's sake but not used:
     * setup attempts are counted in the single bucket `s:setup` (P60).
     */
    public function createFirstAdmin(
        #[\SensitiveParameter] string $setupCode,
        string $displayName,
        string $username,
        #[\SensitiveParameter] string $password,
        string $clientAddress,
    ): IssuedSession {
        $data = $this->requireNeedsAdmin();
        $system = $this->system();
        if ($system === null) {
            throw new \LogicException('The system database is missing.');
        }
        $throttle = new LoginThrottle($system, $this->clock);
        $codeOk = SetupCode::matches($this->readSetupCode($data), $setupCode);

        $wait = $system->db()->write(static function () use ($throttle, $codeOk): int {
            $wait = $throttle->wait([LoginThrottle::SETUP_BUCKET]);
            if ($wait === 0 && !$codeOk) {
                $throttle->failure(LoginThrottle::SETUP_BUCKET, LoginThrottle::SETUP_LIMIT);
            }

            return $wait;
        });
        if ($wait > 0) {
            throw new ValidationFailed([new Problem('setup_code', 'problem.throttled', ['seconds' => $wait])]);
        }

        $problems = $codeOk ? [] : [new Problem('setup_code', 'problem.setup_code.wrong')];
        array_push(
            $problems,
            ...DisplayNames::problems($displayName),
            ...Usernames::problems($username),
            ...PasswordPolicy::problems($password, $username),
        );
        if ($problems !== []) {
            throw new ValidationFailed($problems);
        }

        $hash = $this->hasher->hash($password);
        $users = new Users($system, new Keys($this->random), $this->clock);
        $sessions = new Sessions($system, $this->clock, $this->random);
        $installedAt = Timestamp::format($this->clock->now());
        $version = $this->appVersion;
        /** @var array{0: UserRecord, 1: IssuedSession} $made */
        $made = $system->db()->write(static function () use ($system, $users, $sessions, $throttle, $displayName, $username, $hash, $installedAt, $version): array {
            if ((int) $system->db()->value('SELECT COUNT(*) FROM users') > 0 || $system->isInstalled()) {
                throw new \LogicException('The first Admin already exists.');
            }
            $admin = $users->create($username, $displayName, Role::Admin, $hash, false);
            $system->setMeta(SystemDatabase::META_INSTALLED_AT, $installedAt);
            $system->setMeta(SystemDatabase::META_INSTALLED_WITH, $version);
            $throttle->clear(LoginThrottle::SETUP_BUCKET);

            return [$admin, $sessions->start($admin->id)];
        });
        [$admin, $session] = $made;

        Fs::remove($data->path() . '/' . SetupCode::FILE);
        $actor = new Actor($admin->id, $admin->key, Role::Admin, $admin->displayName);
        (new AuditLog($system, $this->clock))->record(AuditAction::SETUP_COMPLETE, $actor->userId, null, 'user', $actor->userKey);

        return $session;
    }

    /** The located data directory's system database, opened once; null when there is none. */
    private function system(): ?SystemDatabase
    {
        if ($this->system === null) {
            $data = DataDirectory::locate($this->appRoot, $this->configuredDataDir);
            if ($data !== null) {
                $this->system = SystemDatabase::open($data);
            }
        }

        return $this->system;
    }

    private function refuseWhenInstalled(): void
    {
        if ($this->state() === InstallState::Installed) {
            throw new \LogicException('TiffinDB is already installed.');
        }
    }

    private function requireNeedsAdmin(): DataDirectory
    {
        if ($this->state() !== InstallState::NeedsAdmin) {
            throw new \LogicException('This step is only for an installation that needs its first Admin.');
        }
        $data = DataDirectory::locate($this->appRoot, $this->configuredDataDir);
        if ($data === null) {
            throw new \LogicException('The data directory is missing.');
        }

        return $data;
    }

    /** The setup-code file's contents, or '' when it is missing or unreadable (a symbolic link is not read). */
    private function readSetupCode(DataDirectory $data): string
    {
        $file = $data->path() . '/' . SetupCode::FILE;
        if (is_link($file) || !is_file($file)) {
            return '';
        }
        try {
            $contents = ErrorTrap::run(
                static fn (): string|false => file_get_contents($file, false, null, 0, 1024),
                'Cannot read the setup code.',
            );
        } catch (\RuntimeException) {
            return '';
        }

        return $contents === false ? '' : $contents;
    }
}
