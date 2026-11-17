<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Core\Install;

/** The HTTP part of the exposure probe (M1 spec section 10.4); tests use fakes. */
interface HttpProbe
{
    /** One GET of $path on the server that received the current request; null when there was no usable answer. */
    public function get(ProbeTarget $target, string $path): ?ProbeResponse;
}
