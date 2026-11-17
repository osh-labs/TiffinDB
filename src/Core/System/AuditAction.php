<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\System;

/** The audit actions of M1 (M1 spec section 5.6). */
final class AuditAction
{
    public const SETUP_COMPLETE = 'setup.complete';
    public const SIGN_IN = 'auth.sign_in';
    public const SIGN_IN_FAILED = 'auth.sign_in_failed';
    public const SIGN_OUT = 'auth.sign_out';
    public const PASSWORD_CHANGED = 'auth.password_changed';
    public const PEOPLE_CREATED = 'people.created';
    public const PEOPLE_UPDATED = 'people.updated';
    public const PEOPLE_PASSWORD_SET = 'people.password_set';
    public const PEOPLE_DEACTIVATED = 'people.deactivated';
    public const PEOPLE_REACTIVATED = 'people.reactivated';
    public const PEOPLE_ACCESS_CHANGED = 'people.access_changed';
    public const PROJECT_CREATED = 'project.created';
    public const PROJECT_RENAMED = 'project.renamed';
    public const PROJECT_SHARING_CHANGED = 'project.sharing_changed';
    public const TABLE_CREATED = 'schema.table_created';
    public const TABLE_CHANGED = 'schema.table_changed';
    public const TABLE_DELETED = 'schema.table_deleted';
    public const FIELD_CREATED = 'schema.field_created';
    public const FIELD_CHANGED = 'schema.field_changed';
    public const FIELD_MOVED = 'schema.field_moved';
    public const FIELD_DELETED = 'schema.field_deleted';
    public const SNAPSHOT_TAKEN = 'snapshot.taken';
    public const TRASH_EMPTIED = 'trash.emptied';

    /** Values of the `reason` detail of auth.sign_in_failed. */
    public const REASON_UNKNOWN_USER = 'unknown-user';
    public const REASON_BAD_PASSWORD = 'bad-password';
    public const REASON_INACTIVE = 'inactive';
    public const REASON_THROTTLED = 'throttled';

    public const ALL = [
        self::SETUP_COMPLETE, self::SIGN_IN, self::SIGN_IN_FAILED, self::SIGN_OUT, self::PASSWORD_CHANGED,
        self::PEOPLE_CREATED, self::PEOPLE_UPDATED, self::PEOPLE_PASSWORD_SET, self::PEOPLE_DEACTIVATED,
        self::PEOPLE_REACTIVATED, self::PEOPLE_ACCESS_CHANGED, self::PROJECT_CREATED, self::PROJECT_RENAMED,
        self::PROJECT_SHARING_CHANGED, self::TABLE_CREATED, self::TABLE_CHANGED, self::TABLE_DELETED,
        self::FIELD_CREATED, self::FIELD_CHANGED, self::FIELD_MOVED, self::FIELD_DELETED,
        self::SNAPSHOT_TAKEN, self::TRASH_EMPTIED,
    ];

    private function __construct()
    {
    }
}
