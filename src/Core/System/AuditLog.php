<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

use TiffinDB\Core\Storage\Clock;
use TiffinDB\Core\Storage\Database;
use TiffinDB\Core\Storage\Timestamp;

/**
 * The audit log (ADR 0012 decision 4, M1 spec section 5.6).
 *
 * Not a repository: record() is the named exception of section 9.1 and
 * writes in its own short transaction, after the change it describes has
 * committed. It never throws: any failure, including a call made inside
 * another transaction, an action not in AuditAction::ALL, or a detail
 * that is not flat, is logged with error_log as one line naming the action
 * (only when it is a known one) and the exception class, and nothing else.
 *
 * Details must hold no passwords, tokens, attempted usernames, addresses,
 * or field values; callers pass only the keys of section 5.6.
 */
final class AuditLog
{
    public const FAILED_SIGN_IN_RETENTION_DAYS = 90;
    private const DETAIL_KEY = '/^[a-z][a-z_]{0,31}\z/';

    public function __construct(private readonly SystemDatabase $system, private readonly Clock $clock)
    {
    }

    /** @param array<string, string|int|bool|null> $detail flat; no passwords, tokens, or field values */
    public function record(
        string $action,
        ?int $actorUserId,
        ?int $projectId = null,
        ?string $targetKind = null,
        ?string $targetKey = null,
        array $detail = [],
    ): void {
        try {
            if (!in_array($action, AuditAction::ALL, true)) {
                throw new \InvalidArgumentException('Unknown audit action.');
            }
            $encoded = self::encodeDetail($detail);
            $now = $this->clock->now();
            $at = Timestamp::format($now);
            $cutoff = Timestamp::format($now->modify('-' . self::FAILED_SIGN_IN_RETENTION_DAYS . ' days'));
            $this->system->db()->write(static function (Database $db) use (
                $action,
                $actorUserId,
                $projectId,
                $targetKind,
                $targetKey,
                $encoded,
                $at,
                $cutoff,
            ): void {
                if ($action === AuditAction::SIGN_IN_FAILED) {
                    $db->run('DELETE FROM audit_log WHERE action = ? AND at < ?', [AuditAction::SIGN_IN_FAILED, $cutoff]);
                }
                $db->run(
                    'INSERT INTO audit_log (at, actor_user_id, action, project_id, target_kind, target_key, detail)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$at, $actorUserId, $action, $projectId, $targetKind, $targetKey, $encoded],
                );
            });
        } catch (\Throwable $error) {
            $named = in_array($action, AuditAction::ALL, true) ? $action : 'an unknown action';
            error_log('TiffinDB audit log: could not record ' . $named . ' (' . $error::class . ').');
        }
    }

    /**
     * Newest first.
     *
     * @return list<AuditEntry>
     */
    public function recent(int $limit, int $offset = 0): array
    {
        if ($limit < 0 || $offset < 0) {
            throw new \InvalidArgumentException('Limit and offset must be 0 or more.');
        }
        $rows = $this->system->db()->all(
            'SELECT id, at, actor_user_id, action, project_id, target_kind, target_key, detail
               FROM audit_log ORDER BY at DESC, id DESC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
        $entries = [];
        foreach ($rows as $row) {
            $detail = json_decode((string) $row['detail'], true);
            $entries[] = new AuditEntry(
                (int) $row['id'],
                (string) $row['at'],
                $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
                (string) $row['action'],
                $row['project_id'] === null ? null : (int) $row['project_id'],
                $row['target_kind'] === null ? null : (string) $row['target_kind'],
                $row['target_key'] === null ? null : (string) $row['target_key'],
                is_array($detail) ? $detail : [],
            );
        }

        return $entries;
    }

    /** @param array<mixed> $detail */
    private static function encodeDetail(array $detail): string
    {
        if ($detail === []) {
            return '{}';
        }
        foreach ($detail as $key => $value) {
            if (!is_string($key) || preg_match(self::DETAIL_KEY, $key) !== 1) {
                throw new \InvalidArgumentException('Audit detail keys must be short lower-case names.');
            }
            if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('Audit detail values must be strings, integers, booleans, or null.');
            }
        }

        return json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
