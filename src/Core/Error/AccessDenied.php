<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/** The actor may see the object but not do the requested action. */
final class AccessDenied extends CoreException
{
}
