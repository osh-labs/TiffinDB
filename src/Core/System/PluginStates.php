<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

use TiffinDB\Core\Storage\Timestamp;

/**
 * Enabled state of plugins, kept in the system database table `plugins`
 * (system schema version 2; M3 spec sections 4.4 and 12.2, ADR 0015
 * decision 4, P114). One row per plugin whose state an Admin has changed;
 * with no row, the plugin registry applies the default (the five shipped
 * plugins on, every other plugin off).
 *
 * A repository (P51): it opens no transaction of its own, and set()
 * refuses to change rows outside one. It holds no authorization: only
 * Core\App (permission `plugins.manage`) calls set(). Values are
 * validated in code (ADR 0011 decision 2): kind `import` or `export`, id
 * the plugin id pattern, enabled 0 or 1. Rows read back that break these
 * rules (a hand-edited database) are left out of all(), so a damaged row
 * can never enable anything.
 */
final class PluginStates
{
    public const KINDS = ['import', 'export'];
    /** The plugin id pattern of ADR 0015 decision 2 (also Core\Io\Plugin\Manifest::ID_PATTERN). */
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{1,31}\z/';

    public function __construct(private readonly SystemDatabase $system)
    {
    }

    /**
     * Every stored state, by kind then id.
     *
     * @return array<string, bool> '<kind>/<id>' => enabled
     */
    public function all(): array
    {
        $states = [];
        foreach ($this->system->db()->all('SELECT kind, id, enabled FROM plugins ORDER BY kind, id') as $row) {
            $kind = $row['kind'] ?? null;
            $id = $row['id'] ?? null;
            $enabled = $row['enabled'] ?? null;
            if (!is_string($kind) || !is_string($id) || !self::isValid($kind, $id) || ($enabled !== 0 && $enabled !== 1)) {
                continue;
            }
            $states[$kind . '/' . $id] = $enabled === 1;
        }

        return $states;
    }

    /**
     * Stores the state of one plugin. Must run inside Database::write()
     * (\LogicException otherwise); \InvalidArgumentException for a kind,
     * id, user id, or timestamp that is not valid.
     */
    public function set(string $kind, string $id, bool $enabled, int $userId, string $now): void
    {
        $this->system->requireTransaction();
        if (!self::isValid($kind, $id)) {
            throw new \InvalidArgumentException('Not a valid plugin kind and id.');
        }
        if ($userId < 1) {
            throw new \InvalidArgumentException('A plugin state is changed by a user.');
        }
        Timestamp::parse($now);
        $this->system->db()->run(
            'INSERT INTO plugins (kind, id, enabled, changed_at, changed_by) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (kind, id) DO UPDATE SET enabled = excluded.enabled, changed_at = excluded.changed_at,
             changed_by = excluded.changed_by',
            [$kind, $id, $enabled ? 1 : 0, $now, $userId],
        );
    }

    private static function isValid(string $kind, string $id): bool
    {
        return in_array($kind, self::KINDS, true) && preg_match(self::ID_PATTERN, $id) === 1;
    }
}
