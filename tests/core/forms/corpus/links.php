<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group L: link addresses (M5 spec section 12.2), each as <p><a href="...">...</a></p>.
$m = C::MARKER;
$link = static fn (string $href, string $content = 'x'): string => '<p><a href="' . $href . '">' . $content . '</a></p>';
$good = static fn (string $id, string $href): array => [
    'id' => $id, 'text' => C::f($link($href)), 'verdict' => 'accept', 'canonical' => C::canonical($link($href)),
];
$bad = static fn (string $id, string $href): array => [
    'id' => $id, 'text' => C::f($link($href, $m)), 'verdict' => 'refuse', 'reason' => 'href', 'line' => 2,
    'element' => 'a', 'attribute' => 'href',
];

return [
    $good('L1', 'https://example.com/'),
    $good('L2', 'http://192.0.2.10:8080/path?q=1#top'),
    $good('L3', 'HTTPS://example.com/'),
    $good('L4', 'https://xn--r8jz45g.example/'),
    $bad('L5', 'javascript:alert(1)'),
    $bad('L6', 'JaVaScRiPt:alert(1)'),
    $bad('L7', 'vbscript:x'),
    $bad('L8', 'data:text/html,&lt;b&gt;'),
    $bad('L9', 'file:///etc/hosts'),
    $bad('L10', 'ftp://example.com/'),
    $bad('L11', '&#106;avascript:alert(1)'),
    $bad('L12', '&#x6A;avascript:x'),
    $bad('L13', 'java&#x09;script:x'),
    $bad('L14', 'java&#x0A;script:x'),
    $bad('L15', '&#x20;javascript:x'),
    $bad('L16', 'javascript&#x3A;x'),
    $bad('L17', ' https://example.com/'),
    $bad('L18', 'https://example.com/ '),
    $bad('L19', 'https://exa mple.com/'),
    $bad('L20', 'https://example.com/&#x09;'),
    $bad('L21', '//example.com/'),
    $bad('L22', '/relative'),
    $bad('L23', 'example.com'),
    $bad('L24', 'https:example.com'),
    $bad('L25', 'https:/example.com'),
    $bad('L26', '#top'),
    $bad('L27', 'https://user:pw@example.com/'),
    $bad('L28', 'https://user@example.com/'),
    $bad('L29', "https://\u{4F8B}\u{3048}.example/"),
    $bad('L30', 'https://example.com:0/'),
    $bad('L31', 'https://example.com:65536/'),
    $bad('L32', 'https://0x7f.1/'),
    $bad('L33', 'https://example.com/a&quot;b'),
    $bad('L34', 'https://example.com/&lt;x&gt;'),
    $bad('L35', 'https://example.com\\@example.org/'),
    $bad('L36', 'https://example.com/' . str_repeat('a', 2049 - strlen('https://example.com/'))),
    [
        'id' => 'L37', 'text' => C::f('<p><a href="https://example.com/"><a href="https://example.com/">' . $m . '</a></a></p>'),
        'verdict' => 'refuse', 'reason' => 'misplaced', 'line' => 2, 'element' => 'a',
    ],
    [
        'id' => 'L38', 'text' => C::f('<p><a href="https://example.com/"></a></p>'), 'verdict' => 'accept',
        'canonical' => C::canonical('<p><a href="https://example.com/"></a></p>'),
    ],
    [
        'id' => 'L39', 'text' => C::f($link('https://example.com/', '<strong>x</strong>')), 'verdict' => 'accept',
        'canonical' => C::canonical($link('https://example.com/', '<strong>x</strong>')),
    ],
    // Extra: an empty address, other schemes, a literal tab (read as a space), a backtick, bad hosts and ports.
    $bad('L40', ''),
    $bad('L41', 'mailto:user@example.com'),
    $bad('L42', 'tel:+1-555-0100'),
    $bad('L43', "java\tscript:x"),
    $bad('L44', 'https://example.com/`x'),
    $bad('L45', 'https://[::1/'),
    $bad('L46', 'https://example.com:99999/'),
    $bad('L47', 'https://2130706433/'),
    $bad('L48', 'jav&#97;script:x'),
    // L49: references are read before the rule, so a written-out https address is an ordinary link.
    [
        'id' => 'L49', 'text' => C::f($link('&#x68;ttps&#x3A;//example.com/')), 'verdict' => 'accept',
        'canonical' => C::canonical($link('https://example.com/')),
    ],
    $bad('L50', "https://example.com/\u{200B}"),
];
