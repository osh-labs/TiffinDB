<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Query;

/** How Records::create() and update() read the values they are given (`docs/specs/M2-records.md` section 3.1). */
enum InputMode: string
{
    /** Text typed in a form: FieldType::parse(text, options, lenient: false). */
    case Form = 'form';
    /** Text from an import: FieldType::parse(text, options, lenient: true). */
    case Import = 'import';
    /** Canonical PHP values from a core caller: FieldType::check(value, options). */
    case Canonical = 'canonical';
}
