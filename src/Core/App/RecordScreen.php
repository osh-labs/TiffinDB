<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Forms\BoundForm;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * A record for its screen (M2 spec section 7.2): new, live with its bound
 * form, or in the trash (no form). Display names are null when the person
 * is unknown.
 */
final class RecordScreen
{
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly ?int $id,
        public readonly bool $isNew,
        public readonly bool $trashed,
        public readonly ?string $trashedAt,
        public readonly ?string $title,
        public readonly ?string $version,
        public readonly ?BoundForm $form,
        public readonly ?RecordPosition $position,
        public readonly ?string $createdAt,
        public readonly ?string $createdByName,
        public readonly ?string $updatedAt,
        public readonly ?string $updatedByName,
        public readonly ListRequest $request,
    ) {
    }
}
