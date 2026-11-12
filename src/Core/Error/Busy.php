<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/** SQLite stayed busy or locked until the busy timeout ran out. */
final class Busy extends CoreException
{
}
