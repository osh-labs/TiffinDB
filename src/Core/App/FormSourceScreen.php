<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

use TiffinDB\Core\Error\Problem;
use TiffinDB\Core\Forms\FormReport;
use TiffinDB\Core\Schema\TableDef;
use TiffinDB\Core\System\ProjectRecord;

/**
 * The form source view (M5 spec section 9.5).
 *
 * $text is the text area's content: the stored text exactly (also when it
 * is refused), the generated text when there is no row, the text as typed
 * after a refused check or save, or the stored canonical text after a save.
 * It is plain text for the template to escape. $state is 'stored', 'none',
 * or 'refused'; $storedProblem is the stored text's refusal (field
 * 'source', with its line). $report is set after an accepted check or save.
 */
final class FormSourceScreen
{
    /** @param list<Problem> $problems */
    public function __construct(
        public readonly ProjectRecord $project,
        public readonly TableDef $table,
        public readonly string $text,
        public readonly string $base,
        public readonly string $state,
        public readonly ?Problem $storedProblem,
        public readonly array $problems,
        public readonly ?FormReport $report,
        public readonly ?FormSaved $saved,
    ) {
    }
}
