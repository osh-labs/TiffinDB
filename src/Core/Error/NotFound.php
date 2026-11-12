<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/** Something does not exist, or exists but the actor may not see it. */
final class NotFound extends CoreException
{
}
