<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

use TiffinDB\Core\Error\Problem;

/**
 * What FormBinder joins with a form: plain values by field key (Core\App
 * reads them through Core\Query), the text a person typed, problems,
 * links, and files.
 */
final class BindData
{
    /**
     * @param array<string, mixed> $values field key => canonical value
     * @param array<string, string> $submitted field key => text typed (shown instead of the value)
     * @param array<string, list<Problem>> $problems field key => problems
     * @param array<string, list<LinkItem>> $links relation field key => linked records
     * @param array<string, list<FileItem>> $files attachment field key => files
     */
    public function __construct(
        public readonly array $values = [],
        public readonly array $submitted = [],
        public readonly array $problems = [],
        public readonly array $links = [],
        public readonly array $files = [],
        public readonly bool $isNew = false,
    ) {
    }
}
