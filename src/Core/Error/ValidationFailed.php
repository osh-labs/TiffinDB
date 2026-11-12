<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/** Input was refused; every problem found is listed, so a form can show them all at once. */
final class ValidationFailed extends CoreException
{
    /** @param non-empty-list<Problem> $problems */
    public function __construct(public readonly array $problems)
    {
        if ($problems === [] || !array_is_list($problems)) {
            throw new \InvalidArgumentException('ValidationFailed needs a non-empty list of problems.');
        }
        foreach ($problems as $problem) {
            if (!$problem instanceof Problem) {
                throw new \InvalidArgumentException('ValidationFailed accepts Problem values only.');
            }
        }
        parent::__construct('Validation failed with ' . count($problems) . ' problem(s).');
    }
}
