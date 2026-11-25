<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\App;

/**
 * The licence files of one component (M7 spec section 10.1): each file's
 * shipped path, its text as data (valid UTF-8, to be escaped by the
 * template), and whether the text was cut at AboutOperations::MAX_LICENCE_BYTES.
 */
final class LicenceText
{
    /** @param list<array{file: string, text: string, cut: bool}> $files */
    public function __construct(
        public readonly string $name,
        public readonly array $files,
    ) {
    }
}
