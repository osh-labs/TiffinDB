<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Error;

/**
 * Base of the outcomes the core reports to its callers. Messages are for
 * logs only and never hold user data, passwords, tokens, or paths outside
 * the data directory.
 */
abstract class CoreException extends \RuntimeException
{
}
