<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Core\Forms\FormFormat;
use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group Z: limits (M5 spec section 12.2), built with str_repeat.
$m = C::MARKER;
$max = FormFormat::MAX_BYTES;
$open = '<div data-tiffin-form="1">' . "\n" . '<p>';
$close = '</p>' . "\n" . '</div>' . "\n";
$padded = static fn (int $bytes, string $pad = ' '): string => $open . str_repeat($pad, $bytes - strlen($open) - strlen($close)) . $close;
// $n elements in all: the root and $n - 1 dividers, each on its own line.
$elements = static fn (int $n, string $last = '<hr/>'): string => '<div data-tiffin-form="1">' . "\n"
    . str_repeat('<hr/>' . "\n", $n - 2) . $last . "\n" . '</div>' . "\n";
// Z10: $max + 1 bytes of two-byte letters (and one ASCII letter if the count is odd), fewer than $max characters.
$rest = $max + 1 - strlen($open) - strlen($close);
$bytes = $open . str_repeat('a', $rest % 2) . str_repeat("\u{E9}", intdiv($rest, 2)) . $close;
$eight = '<fieldset><p><strong><em><a href="https://example.com/"><strong><em>x</em></strong></a></em></strong></p></fieldset>';
$nine = '<fieldset><p><strong><em><a href="https://example.com/"><strong><em><strong>' . $m . '</strong></em></strong></a></em></strong></p></fieldset>';

return [
    ['id' => 'Z1', 'text' => $padded($max), 'verdict' => 'accept'],
    ['id' => 'Z2', 'text' => $padded($max + 1, $m[0]), 'verdict' => 'refuse', 'reason' => 'too-large', 'line' => null],
    ['id' => 'Z3', 'text' => "\xEF\xBB\xBF" . $padded($max), 'verdict' => 'accept'],
    ['id' => 'Z4', 'text' => $elements(5000), 'verdict' => 'accept'],
    [
        'id' => 'Z5', 'text' => $elements(5001, '<hr title="' . $m . '"/>'), 'verdict' => 'refuse', 'reason' => 'too-many-elements',
        'line' => 5001, 'element' => 'hr',
    ],
    ['id' => 'Z6', 'text' => C::f($eight), 'verdict' => 'accept', 'canonical' => C::canonical('<fieldset>', '  ' . substr($eight, 10, -11), '</fieldset>')],
    ['id' => 'Z7', 'text' => C::f($nine), 'verdict' => 'refuse', 'reason' => 'too-deep', 'line' => 2, 'element' => 'strong'],
    [
        'id' => 'Z8', 'text' => $elements(5000, '<script>' . $m . '</script>'), 'verdict' => 'refuse', 'reason' => 'element',
        'line' => 5000, 'element' => 'script',
    ],
    // Z9: accepted, but its canonical text (each ' written as &apos;) is over the limit; T-FORM-12 skips it by size.
    ['id' => 'Z9', 'text' => $padded($max, "'"), 'verdict' => 'accept'],
    // Extra: the size limit counts bytes, not characters; a malformed problem far down is counted right.
    ['id' => 'Z10', 'text' => $bytes, 'verdict' => 'refuse', 'reason' => 'too-large', 'line' => null],
    ['id' => 'Z11', 'text' => $elements(4500, '<p>' . $m . '</b>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 4500],
];
