<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group D: differential, texts an HTML parser reads differently (M5 spec section 12.2).
$m = C::MARKER;
$k = C::KEY;
$malformed = static fn (string $id, string $inner, ?int $line = 2): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => $line,
];
$accept = static fn (string $id, string $inner, string ...$canonical): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'accept', 'canonical' => C::canonical(...$canonical),
];

return [
    $accept('D1', '<div data-tiffin-field="' . $k . '"/>', '<div data-tiffin-field="' . $k . '"></div>'),
    $accept('D2', '<p/>', '<p></p>'),
    $malformed('D3', '<p>' . $m . '<br>y</p>'),
    // D4, D9, D24: libxml reports the error where it notices it, at the next line or the end of the text.
    $malformed('D4', '<p>' . $m . '</p ', 3),
    [
        'id' => 'D5', 'text' => C::f('<p>' . $m . '<p>b</p></p>'), 'verdict' => 'refuse', 'reason' => 'misplaced', 'line' => 2,
        'element' => 'p',
    ],
    $malformed('D6', '<p>' . $m . '</b></p>'),
    $malformed('D7', '<p title=x>' . $m . '</p>'),
    $malformed('D8', '<p>' . $m . '<!-- b -- c -->d</p>'),
    $malformed('D9', '<p>' . $m . '<!--->b</p>', 4),
    $malformed('D10', '<p>' . $m . '<!-- x --!>b</p>'),
    $malformed('D11', '<p>' . $m . ' ]]> b</p>'),
    $malformed('D12', '<p>' . $m . ' < b</p>'),
    $malformed('D13', '<p>' . $m . ' AT&T</p>'),
    $accept('D14', '<p>a</p><!-- <script>' . $m . '</script> --><p>b</p>', '<p>a</p>', '<p>b</p>'),
    $accept('D15', '<p>a<!-- x -->b</p>', '<p>ab</p>'),
    $accept('D16', "<p><a href='https://example.com/'>x</a></p>", '<p><a href="https://example.com/">x</a></p>'),
    $accept('D17', '<p>&#x3C;script&#x3E;</p>', '<p>&lt;script&gt;</p>'),
    $accept('D18', C::slot(' data-tiffin-label="a &#x3C; b"'), '<div data-tiffin-field="' . $k . '" data-tiffin-label="a &lt; b"></div>'),
    $accept(
        'D19',
        "<div\n  data-tiffin-field=\"" . $k . "\"\n  data-tiffin-label=\"Name\"\n  data-tiffin-span=\"6\"   ></div   >\n<hr\n/>",
        '<div data-tiffin-field="' . $k . '" data-tiffin-label="Name" data-tiffin-span="6"></div>',
        '<hr/>',
    ),
    $accept('D20', '<p>&lt;/textarea&gt;&lt;script&gt;</p>', '<p>&lt;/textarea&gt;&lt;script&gt;</p>'),
    // Extra: HTML reads these as markup, XML as text or as errors.
    $accept('D21', '<p>&lt;img src=x onerror=alert(1)&gt;</p>', '<p>&lt;img src=x onerror=alert(1)&gt;</p>'),
    $malformed('D22', '<p>' . $m . '</P>'),
    $malformed('D23', '<p ' . $m . '>x</p>'),
    $malformed('D24', '<p>' . $m . '</p><p>', 3),
    $malformed('D25', '<p data-tiffin-span="full"data-tiffin-row="1">' . $m . '</p>'),
    $accept('D26', '<p>a --&gt; b</p>', '<p>a --&gt; b</p>'),
    $accept('D27', '<p>&quot;&#39;&#x22;</p>', '<p>&quot;&apos;&quot;</p>'),
    $malformed('D28', '<p>' . $m . '&#x110000;</p>'),
    $malformed('D29', '<p>' . $m . '&#xD800;</p>'),
    $malformed('D30', '<p>' . $m . '&#;</p>'),
    $malformed('D31', '<p>' . $m . '&#x;</p>'),
    $malformed('D32', '<p>' . $m . '&amp</p>'),
];
