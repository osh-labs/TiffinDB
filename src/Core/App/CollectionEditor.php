<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Query\MatchMode;
use TiffinDB\Core\Schema\FieldDef;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The collection editor (M4 spec section 10.4, P163): the draft, the
 * fields rules may use, the preview (matching count and the first five
 * records with the title and up to three columns), and the problems.
 */
final class CollectionEditor
{
    /**
     * @param list<EditorRule> $rules
     * @param list<SortOption> $sorts
     * @param list<FieldDef> $fields
     * @param list<ListRow> $preview
     * @param list<FieldDef> $previewColumns
     * @param list<Problem> $problems
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly ?string $key,
        public readonly string $name,
        public readonly MatchMode $match,
        public readonly array $rules,
        public readonly array $sorts,
        public readonly ?string $sort,
        public readonly array $fields,
        public readonly int $matchCount,
        public readonly array $preview,
        public readonly array $previewColumns,
        public readonly array $problems,
        public readonly bool $canAddRule,
    ) {
    }
}
