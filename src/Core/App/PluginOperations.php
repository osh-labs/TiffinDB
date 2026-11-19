<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Auth\Actor;
use TiffinDB\Core\Auth\Permission;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Error\ValidationFailed;
use TiffinDB\Core\Io\Plugin\PluginInfo;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\Storage\Timestamp;
use TiffinDB\Core\System\AuditAction;

/**
 * The plugin registry for Admins (M3 spec sections 4 and 13.3, ADR 0015
 * decision 4). Every method needs `plugins.manage` (instance-wide, Admins
 * only: a Member gets AccessDenied before any plugin is looked up, so
 * nothing about installed plugins is told to non-Admins) and an active
 * Admin. A change is made inside a system-database write that reads the
 * actor again (Context::requireCurrentAdmin), then audited.
 */
final class PluginOperations
{
    public function __construct(private readonly Context $c)
    {
    }

    /** @return list<PluginInfo> import plugins then export plugins, each by id */
    public function list(Actor $actor): array
    {
        $this->authorise($actor);

        return $this->c->plugins();
    }

    public function get(Actor $actor, string $kind, string $id): PluginInfo
    {
        $this->authorise($actor);

        return $this->find($kind, $id);
    }

    public function enable(Actor $actor, string $kind, string $id): void
    {
        $this->change($actor, $kind, $id, true);
    }

    public function disable(Actor $actor, string $kind, string $id): void
    {
        $this->change($actor, $kind, $id, false);
    }

    private function authorise(Actor $actor): void
    {
        $this->c->authorizer->require($actor, Permission::ManagePlugins);
        $this->c->requireActiveAdmin($actor);
    }

    private function find(string $kind, string $id): PluginInfo
    {
        foreach ($this->c->plugins() as $plugin) {
            if ($plugin->kind === $kind && $plugin->id === $id) {
                return $plugin;
            }
        }

        throw new NotFound('No such plugin.');
    }

    /**
     * Turns a plugin on or off. Broken and Unavailable plugins are refused
     * (`problem.plugin.not_available`); a plugin already in that state is
     * left alone and nothing is audited.
     */
    private function change(Actor $actor, string $kind, string $id, bool $enabled): void
    {
        $this->authorise($actor);
        $changed = $this->c->db()->write(function () use ($actor, $kind, $id, $enabled): bool {
            $this->c->seam('plugins.change');
            $this->c->requireCurrentAdmin($actor);
            // Read inside the write, so two Admins changing the same plugin see each other's change.
            $plugin = $this->find($kind, $id);
            if ($plugin->status === PluginStatus::Broken || $plugin->status === PluginStatus::Unavailable) {
                throw new ValidationFailed([new Problem(null, 'problem.plugin.not_available')]);
            }
            if (($plugin->status === PluginStatus::Enabled) === $enabled) {
                return false;
            }
            $this->c->pluginStates->set($plugin->kind, $plugin->id, $enabled, $actor->userId, Timestamp::format($this->c->clock->now()));

            return true;
        });
        if ($changed) {
            $this->c->audit->record($enabled ? AuditAction::PLUGIN_ENABLED : AuditAction::PLUGIN_DISABLED, $actor->userId, null, 'plugin', $kind . '/' . $id);
        }
    }
}
