<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Authenticator;
use TiffinDB\Core\Auth\Authorizer;
use TiffinDB\Core\Auth\Grants;
use TiffinDB\Core\Auth\LoginThrottle;
use TiffinDB\Core\Auth\PasswordHasher;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Auth\Role;
use TiffinDB\Core\Auth\Sessions;
use TiffinDB\Core\Auth\UserRecord;
use TiffinDB\Core\Auth\Users;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Forms\FormParser;
use TiffinDB\Core\Forms\FormStore;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginLoader;
use TiffinDB\Core\Io\Plugin\PluginScanner;
use TiffinDB\Core\Io\StagingArea;
use TiffinDB\Core\Query\Records;
use TiffinDB\Core\Schema\FieldTypes;
use TiffinDB\Core\Schema\Schema;
use TiffinDB\Core\Schema\SchemaEditor;
use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\DataDirectory;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Keys;
use TiffinDB\Core\Storage\Random;
use TiffinDB\Core\Storage\SnapshotInfo;
use TiffinDB\Core\Storage\Snapshots;
use TiffinDB\Core\System\AuditAction;
use TiffinDB\Core\System\AuditLog;
use TiffinDB\Core\System\PluginStates;
use TiffinDB\Core\System\ProjectRecord;
use TiffinDB\Core\System\ProjectRegistry;
use TiffinDB\Core\System\SystemDatabase;

/**
 * The objects every operation class shares, built once per Operations
 * instance (M1 spec section 9.1), and the checks they all use.
 *
 * @internal Built by Operations only.
 */
final class Context
{
    public readonly Users $users;
    public readonly Grants $grants;
    public readonly Sessions $sessions;
    public readonly LoginThrottle $throttle;
    public readonly Authenticator $authenticator;
    public readonly ProjectRegistry $registry;
    public readonly AuditLog $audit;
    public readonly Authorizer $authorizer;
    public readonly Keys $keys;
    public readonly Snapshots $snapshots;
    public readonly FieldTypes $types;
    public readonly ProjectOpener $opener;
    public readonly PluginStates $pluginStates;

    /** The application's `plugins/` directory (M3 spec section 13); set once, before the first plugin use. */
    private string $pluginsRoot;
    private ?StagingArea $staging = null;
    private ?PluginScanner $scanner = null;
    private ?PluginLoader $loader = null;

    /** @param (\Closure(string): void)|null $seam test seam, see Operations */
    public function __construct(
        public readonly SystemDatabase $system,
        public readonly DataDirectory $data,
        public readonly Clock $clock,
        public readonly Random $random,
        public readonly PasswordHasher $hasher,
        public readonly string $appVersion,
        private readonly ?\Closure $seam = null,
    ) {
        $this->keys = new Keys($random);
        $this->users = new Users($system, $this->keys, $clock);
        $this->grants = new Grants($system, $clock);
        $this->sessions = new Sessions($system, $clock, $random);
        $this->throttle = new LoginThrottle($system, $clock);
        $this->audit = new AuditLog($system, $clock);
        $this->authenticator = new Authenticator($system, $this->users, $this->sessions, $this->throttle, $hasher, $this->audit);
        $this->registry = new ProjectRegistry($system);
        $this->authorizer = new Authorizer($this->grants);
        $this->snapshots = new Snapshots($clock, $this->keys);
        $this->types = FieldTypes::standard($this->keys);
        $this->opener = new ProjectOpener($data, $this->snapshots);
        $this->pluginStates = new PluginStates($system);
        $this->pluginsRoot = dirname(__DIR__, 3) . '/plugins';
    }

    /**
     * Points the plugin registry at another `plugins/` directory. The web
     * composition root passes the application's own (the default); tests
     * pass a temporary one. \LogicException once a plugin was scanned or
     * loaded through this context.
     */
    public function usePluginsRoot(string $pluginsRoot): void
    {
        if ($this->scanner !== null || $this->loader !== null) {
            throw new \LogicException('The plugins directory is fixed once plugins have been used.');
        }
        $this->pluginsRoot = rtrim($pluginsRoot, '/');
    }

    public function pluginsRoot(): string
    {
        return $this->pluginsRoot;
    }

    public function staging(): StagingArea
    {
        return $this->staging ??= new StagingArea($this->data, $this->clock, $this->keys);
    }

    public function pluginScanner(): PluginScanner
    {
        return $this->scanner ??= new PluginScanner($this->pluginsRoot);
    }

    public function pluginLoader(): PluginLoader
    {
        return $this->loader ??= new PluginLoader($this->pluginsRoot);
    }

    /**
     * Every plugin found, with the stored enabled states applied (scanned
     * again on every call, so a plugin an Admin has just turned off, or
     * that has become broken, is seen at once).
     *
     * @return list<PluginInfo>
     */
    public function plugins(): array
    {
        return $this->pluginScanner()->scan($this->pluginStates->all());
    }

    /**
     * Outside a transaction: the acting person, read now, must still exist,
     * be active, and hold the key and the role the Actor was built with
     * (AccessDenied otherwise). Every import, export, archive, and plugin
     * operation calls it after its permission check, so an Admin who has
     * been deactivated or made a Member since the session was resumed, and
     * an Actor naming nobody, are refused before anything is read or
     * written. The permission check decides on the role; this check makes
     * sure that role is still the person's.
     */
    public function requireCurrentActor(Actor $actor): UserRecord
    {
        $current = $this->users->byId($actor->userId);
        if ($current === null || !$current->active || $current->role !== $actor->role || $current->key !== $actor->userKey) {
            throw new AccessDenied('The acting person is not who the session says.');
        }

        return $current;
    }

    public function db(): Database
    {
        return $this->system->db();
    }

    /**
     * The active project for a key, after Authorizer::require. An unknown
     * or malformed key, a project that is not active, and a project a
     * Member holds no grant for are all NotFound.
     */
    public function project(Actor $actor, Permission $permission, string $projectKey): ProjectRecord
    {
        $project = $this->registry->byKey($projectKey);
        if ($project === null) {
            throw new NotFound('No such project.');
        }
        $this->authorizer->require($actor, $permission, $project);

        return $project;
    }

    /**
     * Inside the system-database write of a people, role, or grant change:
     * the acting person, read again, must still exist, be active, and be an
     * Admin. Authorizer trusts the role read when the session resumed; this
     * closes the gap between that read and the change (ledger section 7).
     */
    public function requireCurrentAdmin(Actor $actor): UserRecord
    {
        $this->system->requireTransaction();
        $current = $this->users->byId($actor->userId);
        if ($current === null || !$current->active || $current->role !== Role::Admin || $current->key !== $actor->userKey) {
            throw new AccessDenied('The acting person is no longer an active Admin.');
        }

        return $current;
    }

    /** Inside a write: the acting person, read again, must still exist and be active. */
    public function requireCurrentPerson(Actor $actor): UserRecord
    {
        $this->system->requireTransaction();
        $current = $this->users->byId($actor->userId);
        if ($current === null || !$current->active || $current->key !== $actor->userKey) {
            throw new AccessDenied('The acting person can no longer sign in.');
        }

        return $current;
    }

    /** Test seam (T-APP-30): called inside the write, between a guarded check and its change. */
    public function seam(string $point): void
    {
        if ($this->seam !== null) {
            ($this->seam)($point);
        }
    }

    /**
     * A schema editor for a project acting as $actor. Every snapshot it
     * takes is recorded at once as `snapshot.taken`: the snapshot exists
     * from that moment, whether or not the change that follows succeeds.
     *
     * Each structural change regenerates the default forms of the tables
     * it touches inside its own transaction (M2 spec section 5.6): an
     * exception from the regeneration rolls the whole change back.
     */
    public function editor(ProjectRecord $project, Database $db, Actor $actor): SchemaEditor
    {
        return new SchemaEditor(
            $db,
            $this->data->projectFolder($project->key),
            $this->types,
            $this->keys,
            $this->clock,
            $this->snapshots,
            $actor->userKey,
            function (SnapshotInfo $info) use ($project, $actor): void {
                $this->recordSnapshot($project, $actor, $info);
            },
            function (Database $db, array $tableKeys): void {
                $this->forms($db)->regenerate(new Schema($db, $this->types), array_values($tableKeys));
            },
        );
    }

    /**
     * The record layer for an opened project (M2 spec section 7.1). The
     * caller has already authorised $project; $schema must be current.
     */
    public function records(ProjectRecord $project, Database $db, Schema $schema): Records
    {
        return new Records($db, $schema, $this->types, $this->clock);
    }

    /** Stored forms of an opened project; the caller has already authorised it. */
    public function forms(Database $db): FormStore
    {
        return new FormStore($db, $this->keys, $this->clock, new FormParser($this->types));
    }

    public function recordSnapshot(ProjectRecord $project, Actor $actor, SnapshotInfo $info): void
    {
        $this->audit->record(
            AuditAction::SNAPSHOT_TAKEN,
            $actor->userId,
            $project->id,
            'snapshot',
            $info->file,
            ['reason' => $info->reason->value],
        );
    }

    /**
     * Project keys that name active projects, as ids; anything else
     * (unknown, malformed, not a string, still being created) is ignored.
     *
     * @param array<mixed> $projectKeys
     * @return list<int>
     */
    public function activeProjectIds(array $projectKeys): array
    {
        $ids = [];
        foreach ($projectKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $project = $this->registry->byKey($key);
            if ($project !== null && $project->isActive()) {
                $ids[$project->id] = $project->id;
            }
        }

        return array_values($ids);
    }
}
