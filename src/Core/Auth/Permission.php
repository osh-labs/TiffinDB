<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Auth;

/** Named permissions checked by Authorizer (M1 spec section 6.9). */
enum Permission: string
{
    case ManagePeople = 'people.manage';
    case CreateProject = 'project.create';
    case ViewProject = 'project.view';
    case ManageProject = 'project.manage';
    case ChangeSchema = 'schema.change';
    case ViewRecords = 'records.view';
    case EditRecords = 'records.edit';
    case TrashRecords = 'records.trash';
    case EmptyTrash = 'trash.empty';
    case ManageSnapshots = 'snapshots.manage';
    case ViewAudit = 'audit.view';
    case ImportExport = 'io.use';
    case ManageCollections = 'collections.manage';
    case EditForms = 'forms.edit';
    case ManagePlugins = 'plugins.manage';

    /** Instance-wide: Admins only, and no project is involved. */
    public function isInstanceWide(): bool
    {
        return match ($this) {
            self::ManagePeople, self::CreateProject, self::ViewAudit, self::ManagePlugins => true,
            default => false,
        };
    }

    /** Project-scoped and open to a Member who holds a grant on the project. */
    public function isOpenToGrantedMembers(): bool
    {
        return match ($this) {
            self::ViewProject, self::ViewRecords, self::EditRecords, self::TrashRecords, self::ManageCollections => true,
            default => false,
        };
    }
}
