<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Forms\EditorRow;
use TiffinDB\Core\Forms\FormReport;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The simple form editor's page (M5 spec section 9.2).
 *
 * $draft is the canonical text of the working copy, for the page's hidden
 * field; it is parsed again on every request. $base is the save token.
 * $state is 'stored', 'none', or 'refused' (with $storedProblem).
 * $isStandard is true when the draft equals the generated text. $typed
 * holds refused labels and legends as typed, by input name. $draftRefused
 * is true when the posted draft could not be read and the stored form is
 * shown again. $focus names the row and control that take the focus.
 */
final class FormEditorScreen
{
    /**
     * @param list<EditorRow> $rows
     * @param list<Problem> $problems
     * @param array<string, string> $typed
     * @param array{path: ?string, control: string}|null $focus
     */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly array $rows,
        public readonly FormReport $report,
        public readonly string $draft,
        public readonly string $base,
        public readonly string $state,
        public readonly ?Problem $storedProblem,
        public readonly bool $isStandard,
        public readonly array $problems,
        public readonly array $typed,
        public readonly bool $draftRefused,
        public readonly ?array $focus,
        public readonly ?FormSaved $saved,
    ) {
    }
}
