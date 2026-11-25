<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group B: attributes (M5 spec section 12.2).
$m = C::MARKER;
$at = static fn (string $id, string $inner, string $element, ?string $attribute, string $reason = 'attribute'): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => $reason, 'line' => 2,
    'element' => $element, 'attribute' => $attribute,
];

return [
    $at('B1', '<p onclick="' . $m . '">x</p>', 'p', 'onclick'),
    $at('B2', '<p ONCLICK="' . $m . '">x</p>', 'p', 'ONCLICK'),
    $at('B3', '<p onmouseover="' . $m . '">x</p>', 'p', 'onmouseover'),
    $at('B4', '<img src="attachment:1" alt="" onerror="' . $m . '"/>', 'img', 'onerror'),
    $at('B5', '<p style="color:red">' . $m . '</p>', 'p', 'style'),
    $at('B6', '<p class="x">' . $m . '</p>', 'p', 'class'),
    $at('B7', '<p id="x">' . $m . '</p>', 'p', 'id'),
    $at('B8', '<p hidden="">' . $m . '</p>', 'p', 'hidden'),
    $at('B9', '<p tabindex="0">' . $m . '</p>', 'p', 'tabindex'),
    $at('B10', '<p role="button">' . $m . '</p>', 'p', 'role'),
    $at('B11', '<p aria-label="' . $m . '">x</p>', 'p', 'aria-label'),
    $at('B12', '<p lang="en">' . $m . '</p>', 'p', 'lang'),
    $at('B13', '<p dir="rtl">' . $m . '</p>', 'p', 'dir'),
    $at('B14', '<p title="' . $m . '">x</p>', 'p', 'title'),
    $at('B15', '<p><a href="https://example.com/" target="_blank">' . $m . '</a></p>', 'a', 'target'),
    $at('B16', '<p><a href="https://example.com/" rel="opener">' . $m . '</a></p>', 'a', 'rel'),
    $at('B17', '<p xml:lang="en">' . $m . '</p>', 'p', 'xml:lang'),
    $at('B18', '<p xml:space="preserve">' . $m . '</p>', 'p', 'xml:space'),
    $at('B19', '<p xml:base="https://example.com/">' . $m . '</p>', 'p', 'xml:base'),
    [
        'id' => 'B20', 'text' => '<div data-tiffin-form="1" xmlns="http://www.w3.org/1999/xhtml">' . "\n" . '<p>' . $m . '</p>' . "\n" . '</div>',
        'verdict' => 'refuse', 'reason' => 'root', 'line' => 1, 'element' => 'div', 'attribute' => 'xmlns',
    ],
    $at('B21', '<p xmlns:x="urn:x" x:y="1">' . $m . '</p>', 'p', 'xmlns:x'),
    $at('B22', C::slot(' data-tiffin-span="6" DATA-TIFFIN-SPAN="4" data-tiffin-label="' . $m . '"'), 'div', 'DATA-TIFFIN-SPAN'),
    // B23: libxml reports the duplicate attribute itself.
    ['id' => 'B23', 'text' => C::f('<p data-tiffin-span="6" data-tiffin-span="4">' . $m . '</p>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 2],
    $at('B24', C::slot(' data-tiffin-other="1" data-tiffin-label="' . $m . '"'), 'div', 'data-tiffin-other'),
    $at('B25', '<img alt="' . $m . '"/>', 'img', 'src'),
    $at('B26', '<img src="attachment:1"/><p>' . $m . '</p>', 'img', 'alt'),
    $at('B27', '<p><a>' . $m . '</a></p>', 'a', 'href'),
    $at('B28', '<div data-tiffin-label="' . $m . '"></div>', 'div', 'data-tiffin-field'),
    $at('B29', '<p ' . str_repeat('b', 41) . '="' . $m . '">x</p>', 'p', null),
    // Extra: placement on elements that take none, a legend attribute, the root marker inside.
    $at('B30', '<p><strong data-tiffin-span="full">' . $m . '</strong></p>', 'strong', 'data-tiffin-span'),
    $at('B31', '<fieldset><legend data-tiffin-section="x">' . $m . '</legend></fieldset>', 'legend', 'data-tiffin-section'),
    $at('B32', '<p data-tiffin-form="1">' . $m . '</p>', 'p', 'data-tiffin-form'),
    $at('B33', '<p><a href="https://example.com/" onclick="' . $m . '">x</a></p>', 'a', 'onclick'),
    $at('B34', '<img src="attachment:1" alt="" srcset="' . $m . '"/>', 'img', 'srcset'),
    $at('B35', '<p formaction="' . $m . '">x</p>', 'p', 'formaction'),
    $at('B36', '<hr data-tiffin-field="' . C::KEY . '"/><p>' . $m . '</p>', 'hr', 'data-tiffin-field'),
];
