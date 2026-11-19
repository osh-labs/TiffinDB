<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\App;

use PHPUnit\Framework\Attributes\CoversClass;
use TiffinDB\Core\App\PluginOperations;
use TiffinDB\Core\Error\AccessDenied;
use TiffinDB\Core\Error\NotFound;
use TiffinDB\Core\Io\Plugin\PluginStatus;
use TiffinDB\Core\System\AuditAction;

require_once __DIR__ . '/AppTestCase.php';

/** T-APP-94: plugins enabled and disabled through the operations (M3 spec section 13.3). */
#[CoversClass(PluginOperations::class)]
final class PluginOperationsTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ops->usePluginsRoot($this->fx->pluginsRoot());
    }

    public function testListAndGet(): void
    {
        $plugins = $this->ops->plugins();
        $refs = array_map(static fn ($p) => $p->ref(), $plugins->list($this->admin));
        foreach (['import/csv', 'import/example', 'import/needs', 'export/broken', 'export/csv', 'export/json'] as $ref) {
            self::assertContains($ref, $refs);
        }
        self::assertSame(PluginStatus::Enabled, $plugins->get($this->admin, 'import', 'csv')->status);
        self::assertSame(PluginStatus::Disabled, $plugins->get($this->admin, 'import', 'example')->status);
        self::assertSame(PluginStatus::Unavailable, $plugins->get($this->admin, 'import', 'needs')->status);
        self::assertSame(PluginStatus::Broken, $plugins->get($this->admin, 'export', 'broken')->status);
        $this->assertThrows(NotFound::class, fn () => $plugins->get($this->admin, 'import', 'nothing'));
        $this->assertThrows(NotFound::class, fn () => $plugins->get($this->admin, 'other', 'csv'));
    }

    public function testEnableAndDisableAreAudited(): void
    {
        $plugins = $this->ops->plugins();
        $plugins->enable($this->admin, 'import', 'example');
        self::assertSame(PluginStatus::Enabled, $plugins->get($this->admin, 'import', 'example')->status);
        $entry = $this->lastAudit(AuditAction::PLUGIN_ENABLED);
        self::assertSame([$this->admin->userId, null, 'plugin', 'import/example', []], [$entry->actorUserId, $entry->projectId, $entry->targetKind, $entry->targetKey, $entry->detail]);
        $row = $this->fx->system->db()->all('SELECT kind, id, enabled, changed_by FROM plugins');
        self::assertSame([['kind' => 'import', 'id' => 'example', 'enabled' => 1, 'changed_by' => $this->admin->userId]], $row);

        $plugins->disable($this->admin, 'export', 'csv');
        self::assertSame(PluginStatus::Disabled, $plugins->get($this->admin, 'export', 'csv')->status);
        $entry = $this->lastAudit(AuditAction::PLUGIN_DISABLED);
        self::assertSame(['plugin', 'export/csv'], [$entry->targetKind, $entry->targetKey]);
        $plugins->enable($this->admin, 'export', 'csv');
        self::assertSame(PluginStatus::Enabled, $plugins->get($this->admin, 'export', 'csv')->status);
    }

    public function testANoOpIsNotAudited(): void
    {
        $plugins = $this->ops->plugins();
        $before = $this->auditActions();
        $plugins->enable($this->admin, 'import', 'csv');
        $plugins->disable($this->admin, 'import', 'example');
        self::assertSame($before, $this->auditActions());
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM plugins'));
    }

    public function testBrokenAndUnavailablePluginsAreRefused(): void
    {
        $plugins = $this->ops->plugins();
        foreach ([['import', 'needs'], ['export', 'broken']] as [$kind, $id]) {
            foreach (['enable', 'disable'] as $method) {
                self::assertSame([[null, 'problem.plugin.not_available', []]], self::problems(fn () => $plugins->{$method}($this->admin, $kind, $id)));
            }
        }
        $this->assertThrows(NotFound::class, fn () => $plugins->enable($this->admin, 'import', 'nothing'));
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM plugins'));
        self::assertNotContains(AuditAction::PLUGIN_ENABLED, $this->auditActions());
    }

    /** The actor is read again inside the write: one deactivated after the first check changes nothing. */
    public function testAnAdminDeactivatedDuringTheChangeIsRefused(): void
    {
        $other = $this->fx->addPerson('admin2', 'Second Admin', \TiffinDB\Core\Auth\Role::Admin);
        $ops = $this->fx->operations(null, null, function (string $point): void {
            if ($point === 'plugins.change') {
                $this->fx->system->db()->run('UPDATE users SET active = 0 WHERE username = ?', ['admin2']);
            }
        });
        $ops->usePluginsRoot($this->fx->pluginsRoot());
        $this->assertThrows(AccessDenied::class, fn () => $ops->plugins()->enable($other, 'import', 'example'));
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM plugins'));
        self::assertNotContains(AuditAction::PLUGIN_ENABLED, $this->auditActions());
    }

    public function testADeactivatedOrDemotedActorIsRefused(): void
    {
        $plugins = $this->ops->plugins();
        $other = $this->fx->addPerson('admin2', 'Second Admin', \TiffinDB\Core\Auth\Role::Admin);
        $this->fx->setActive('admin2', false);
        foreach (['list', 'get', 'enable', 'disable'] as $method) {
            $this->assertThrows(AccessDenied::class, fn () => $method === 'list' ? $plugins->list($other) : $plugins->{$method}($other, 'import', 'example'));
        }
        self::assertSame([], $this->fx->system->db()->all('SELECT * FROM plugins'));
    }
}
