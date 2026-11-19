<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Web;

use DateTimeImmutable;
use TiffinDB\Core\App\Operations;
use TiffinDB\Core\Auth\SignedIn;
use TiffinDB\Core\Install\HttpProbe;
use TiffinDB\Core\Install\Installer;
use TiffinDB\Core\Install\InstallState;

/**
 * What the kernel and controllers need from the core (M1 spec part 2
 * section 2.8). CoreServices is the production implementation and the one
 * web class that constructs core objects (P58); tests give the kernel a
 * scripted fake.
 *
 * An implementation lives for one request: close() ends it and drops
 * everything it built, so the next call starts afresh.
 *
 * Beyond the methods in section 2.8, four were added for the kernel
 * (recorded in the WP7 report): endSession(), userSettings(),
 * newCsrfToken(), and now(). They keep `Core\Storage` and `Core\System`
 * names out of every other web class. M3 adds uploadLimits().
 */
interface Services
{
    /** The installation state; computed at most once per request. */
    public function installState(): InstallState;

    /** @throws \LogicException once installed */
    public function installer(): Installer;

    /** @throws \LogicException before installation */
    public function operations(): Operations;

    public function probe(): HttpProbe;

    /** The person and session for a cookie token, or null (as AuthOperations::resume). */
    public function resume(#[\SensitiveParameter] string $token): ?SignedIn;

    /**
     * Ends the session for this token when it exists, writing no audit entry
     * (used to retire the previous cookie's session when a new one starts).
     */
    public function endSession(#[\SensitiveParameter] string $token): void;

    /**
     * The flash notice for the session, cleared on read.
     *
     * @return array{key: string, params: array<string, string|int>}|null
     */
    public function takeFlash(#[\SensitiveParameter] string $token): ?array;

    /** @param array<string, string|int> $params */
    public function setFlash(#[\SensitiveParameter] string $token, string $key, array $params = []): void;

    /**
     * The person's stored appearance settings (a `user_settings` row as
     * column => value), for Appearance::fromSettings().
     *
     * @return array<string, mixed>
     */
    public function userSettings(int $userId): array;

    /** A new anonymous CSRF token (Csrf::generate: 64 lower-case hex characters). */
    public function newCsrfToken(): string;

    /** The current time from the core clock. */
    public function now(): DateTimeImmutable;

    /**
     * PHP's upload limits (M3 spec part 2 section 2.3): the kernel's 413
     * check and the size limit upload screens state.
     */
    public function uploadLimits(): UploadLimits;

    /** Closes the databases and forgets everything built for this request. */
    public function close(): void;
}
