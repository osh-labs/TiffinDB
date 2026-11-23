<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Storage;

/**
 * The file system cannot lock a project's `project.lock` (flock is
 * disabled or unsupported, or the lock file is not a regular file in a
 * real directory). Never shown as such: restore and deletion turn it into
 * the problem `problem.lock.unavailable` (M7 spec section 4.1).
 */
final class LocksUnavailable extends \RuntimeException
{
}
