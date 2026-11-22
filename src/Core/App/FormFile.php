<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * A table's default form as a file to download (M5 spec section 9.1): the
 * stored text exactly as stored, or the generated text when there is none.
 * The file name is the table's name followed by " form.html"; the web layer
 * cleans it before it reaches a header.
 */
final class FormFile
{
    public function __construct(
        public readonly string $fileName,
        public readonly string $text,
    ) {
    }
}
