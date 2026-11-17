<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

namespace TiffinDB\Tests\Core\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TiffinDB\Core\Auth\Csrf;

/**
 * Csrf::matches compares in constant time. A static check: a plain === or
 * strcmp gives the same results as hash_equals in every behaviour test but
 * leaks timing (WP7 security review; such a mutation survived).
 */
#[CoversClass(Csrf::class)]
final class CsrfConstantTimeTest extends TestCase
{
    public function testMatchingComparesInConstantTime(): void
    {
        $method = new \ReflectionMethod(Csrf::class, 'matches');
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertSame(1, preg_match_all('/\bhash_equals\(\s*\$expected\s*,\s*\$given\s*\)/', $body));
        $this->assertMatchesRegularExpression('/return\s+hash_equals\(\s*\$expected\s*,\s*\$given\s*\)\s*;/', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/\$expected\s*[!=]==?\s*\$given|\$given\s*[!=]==?\s*\$expected|strcmp|strcasecmp|strncmp|\bsimilar_text|\blevenshtein/',
            $body,
        );
    }
}
