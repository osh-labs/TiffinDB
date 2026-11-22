<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group T: text and characters (M5 spec section 12.2).
$m = C::MARKER;
$refuse = static fn (string $id, string $inner, string $reason, ?int $line = 2, array $names = []): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => $reason, 'line' => $line, ...$names,
];
$accept = static fn (string $id, string $inner, ?string $canonicalLine = null): array => array_filter([
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'accept',
    'canonical' => $canonicalLine === null ? null : C::canonical($canonicalLine),
], static fn (mixed $v): bool => $v !== null);
$p = static fn (string $text): string => '<p>' . $text . '</p>';
$legend = static fn (string $text): string => '<fieldset><legend>' . $text . '</legend></fieldset>';

return [
    $accept('T1', $p('&lt;script&gt;' . $m . '&lt;/script&gt;'), $p('&lt;script&gt;' . $m . '&lt;/script&gt;')),
    $accept('T2', $p('a &amp; b &apos; &quot;'), $p('a &amp; b &apos; &quot;')),
    $refuse('T3', $p($m . '&nbsp;'), 'malformed'),
    $refuse('T4', $p($m . '&#0;'), 'malformed'),
    $refuse('T5', $p($m . '&#x1F;'), 'malformed'),
    $refuse('T6', $p($m . '&#x7F;'), 'text-value'),
    $refuse('T7', $p($m . '&#x85;'), 'text-value'),
    $refuse('T8', $p('&#x202E;' . $m), 'text-value'),
    $refuse('T9', $p('&#x2066;' . $m), 'text-value'),
    $refuse('T10', $p('&#x200B;' . $m), 'text-value'),
    $refuse('T11', $p('&#xAD;' . $m), 'text-value'),
    $refuse('T12', $p('&#xFEFF;' . $m), 'text-value'),
    $refuse('T13', $p($m . '&#xFFFE;'), 'malformed'),
    $refuse('T13b', $p($m . "\u{FFFE}"), 'malformed'),
    $accept('T14', $p("a&#x200D;b"), $p("a\u{200D}b")),
    $accept('T15', $p('a&#x9;b&#xA;c'), $p("a\tb\nc")),
    $refuse('T16', $p('a&#xD;' . $m), 'text-value'),
    $accept('T17', $p("a\rb"), $p("a\nb")),
    $accept('T18', $p("\u{E9} \u{FC} \u{65E5}\u{672C} \u{1F600}"), $p("\u{E9} \u{FC} \u{65E5}\u{672C} \u{1F600}")),
    ['id' => 'T19', 'text' => '<div data-tiffin-form="1">' . $m . '</div>', 'verdict' => 'refuse', 'reason' => 'text', 'line' => 1],
    $refuse('T20', '<fieldset><legend>Details</legend>' . $m . '</fieldset>', 'text'),
    $refuse('T21', $legend('') . '<p>' . $m . '</p>', 'text-value'),
    $refuse('T22', $legend($m . str_repeat('x', 91)), 'text-value'),
    $refuse('T23', $legend($m . '&#x202E;'), 'text-value'),
    $refuse('T24', $legend('<strong>' . $m . '</strong>'), 'content', 2, ['element' => 'strong']),
    $refuse('T25', '<hr>' . $m . '</hr>', 'content'),
    $refuse('T26', $p('<br>' . $m . '</br>'), 'content'),
    $refuse('T27', '<div data-tiffin-field="' . C::KEY . '">' . $m . '</div>', 'content'),
    // T28: libxml reports the extra content at the end of the document.
    ['id' => 'T28', 'text' => C::f($p('x')) . $m, 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 4],
    $refuse('T29', $p('a&#xFDD0;' . $m), 'text-value'),
    $refuse('T30', $p('a&#x1FFFE;' . $m), 'text-value'),
    $refuse('T31', $p('a&#x10FFFF;' . $m), 'text-value'),
    $refuse('T32', C::slot(' data-tiffin-label="a&#xFDD0;' . $m . '"'), 'attribute-value', 2, ['element' => 'div', 'attribute' => 'data-tiffin-label']),
    $refuse('T33', $legend($m . '&#xFDEF;'), 'text-value'),
    $refuse('T34', '<img src="attachment:1" alt="' . $m . '&#x2FFFF;"/>', 'attribute-value', 2, ['element' => 'img', 'attribute' => 'alt']),
    $accept('T35', $p('&#xE000;&#x378;'), $p("\u{E000}\u{378}")),
    // Extra: noncharacters written literally, other planes, the edges of the range, and the line separators.
    $refuse('T36', $p($m . "\u{FDEF}"), 'text-value'),
    $refuse('T37', $p($m . "\u{10FFFE}"), 'text-value'),
    $refuse('T38', $p($m . '&#x5FFFF;'), 'text-value'),
    $accept('T39', $p('&#xFDCF;&#xFDF0;&#xFFFD;'), $p("\u{FDCF}\u{FDF0}\u{FFFD}")),
    $refuse('T40', $p($m . '&#x2028;'), 'text-value'),
    $refuse('T41', $p($m . '&#x2029;'), 'text-value'),
    $refuse('T42', '<h2>' . $m . '<em>&#x1;</em></h2>', 'malformed'),
    $refuse('T43', '<h3><em>&#x7F;' . $m . '</em></h3>', 'text-value'),
    $refuse('T44', $p("<a href=\"https://example.com/\">\u{FFFF}" . $m . '</a>'), 'malformed'),
    $accept('T45', $p('&gt; &#62; >'), $p('&gt; &gt; &gt;')),
];
