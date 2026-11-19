<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\System\SystemDatabase;

/**
 * The core operations (M1 spec section 9, ADR 0012): the one entry point
 * through which the web layer, and later the MCP adapter, act. Every
 * operation that takes an Actor checks its permission itself; nothing
 * outside Core\App makes an authorization decision.
 *
 * One instance serves one request. It builds the shared repositories once
 * and keeps every project database it opens until close().
 *
 * Transactions (P51): the operation opens exactly one system-database
 * write() around a check and the change it guards; repositories never open
 * one. Audit entries are written after the change commits.
 */
final class Operations
{
    private readonly Context $context;
    private ?AuthOperations $auth = null;
    private ?PeopleOperations $people = null;
    private ?ProjectOperations $projects = null;
    private ?SchemaOperations $schema = null;
    private ?TrashOperations $trash = null;
    private ?SnapshotOperations $snapshots = null;
    private ?AuditOperations $audit = null;
    private ?RecordOperations $records = null;

    /**
     * @param (\Closure(string): void)|null $seam Test seam (T-APP-30), not for
     *        production use: called with a point name inside a guarded
     *        system-database write, after the check and before the change
     *        (`people.last_admin`).
     */
    public function __construct(
        SystemDatabase $system,
        DataDirectory $data,
        Clock $clock,
        Random $random,
        PasswordHasher $hasher,
        string $appVersion,
        ?\Closure $seam = null,
    ) {
        $this->context = new Context($system, $data, $clock, $random, $hasher, $appVersion, $seam);
    }

    public function auth(): AuthOperations
    {
        return $this->auth ??= new AuthOperations($this->context);
    }

    public function people(): PeopleOperations
    {
        return $this->people ??= new PeopleOperations($this->context);
    }

    public function projects(): ProjectOperations
    {
        return $this->projects ??= new ProjectOperations($this->context);
    }

    public function schema(): SchemaOperations
    {
        return $this->schema ??= new SchemaOperations($this->context);
    }

    public function trash(): TrashOperations
    {
        return $this->trash ??= new TrashOperations($this->context);
    }

    public function snapshots(): SnapshotOperations
    {
        return $this->snapshots ??= new SnapshotOperations($this->context);
    }

    public function audit(): AuditOperations
    {
        return $this->audit ??= new AuditOperations($this->context);
    }

    public function records(): RecordOperations
    {
        return $this->records ??= new RecordOperations($this->context);
    }

    /** Closes every project database this instance opened. The system database is the caller's. */
    public function close(): void
    {
        $this->context->opener->closeAll();
    }
}
