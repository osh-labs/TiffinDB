<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Forms;

/**
 * What FormStore::saveDefault or resetDefault did (M5 spec section 7.1):
 * the default form's key, the stored text, whether it is the generated
 * text, and whether anything was written.
 */
final class FormWrite
{
    public function __construct(
        public readonly string $key,
        public readonly string $html,
        public readonly bool $generated,
        public readonly bool $changed,
    ) {
    }
}
