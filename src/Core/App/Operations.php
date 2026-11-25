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
    private ?SettingsOperations $settings = null;
    private ?ImportOperations $imports = null;
    private ?ExportOperations $exports = null;
    private ?ArchiveOperations $archives = null;
    private ?PluginOperations $plugins = null;
    private ?AttachmentOperations $attachments = null;
    private ?CollectionOperations $collections = null;
    private ?FormOperations $forms = null;
    private ?RecoveryOperations $recovery = null;
    private ?AboutOperations $about = null;

    /**
     * @param (\Closure(string): void)|null $seam Test seam (T-APP-30), not for
     *        production use: called with a point name inside a guarded
     *        system-database write, after the check and before the change
     *        (`people.last_admin`); and `recovery.hashed`, between the
     *        recovery throttle check and the write that checks the code
     *        (M7 spec section 9.3 steps 4 and 5), outside any write.
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

    public function settings(): SettingsOperations
    {
        return $this->settings ??= new SettingsOperations($this->context);
    }

    public function imports(): ImportOperations
    {
        return $this->imports ??= new ImportOperations($this->context);
    }

    public function exports(): ExportOperations
    {
        return $this->exports ??= new ExportOperations($this->context);
    }

    public function archives(): ArchiveOperations
    {
        return $this->archives ??= new ArchiveOperations($this->context);
    }

    public function plugins(): PluginOperations
    {
        return $this->plugins ??= new PluginOperations($this->context);
    }

    public function attachments(): AttachmentOperations
    {
        return $this->attachments ??= new AttachmentOperations($this->context);
    }

    public function collections(): CollectionOperations
    {
        return $this->collections ??= new CollectionOperations($this->context);
    }

    public function forms(): FormOperations
    {
        return $this->forms ??= new FormOperations($this->context);
    }

    /** Admin recovery by a code file (M7 spec section 9); the person is signed out. */
    public function recovery(): RecoveryOperations
    {
        return $this->recovery ??= new RecoveryOperations($this->context);
    }

    /** The About facts (M7 spec section 10), from this installation's own application root. */
    public function about(): AboutOperations
    {
        return $this->about ??= new AboutOperations($this->context);
    }

    /**
     * The `plugins/` directory the registry reads (M3 spec section 13): the
     * application's own by default. The constructor keeps its arguments
     * (P78), so the composition root and tests set it here, before the
     * first import, export, or plugin operation.
     */
    public function usePluginsRoot(string $pluginsRoot): self
    {
        $this->context->usePluginsRoot($pluginsRoot);

        return $this;
    }

    /** Closes every project database this instance opened. The system database is the caller's. */
    public function close(): void
    {
        $this->context->opener->closeAll();
    }
}
