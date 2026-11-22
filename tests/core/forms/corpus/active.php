<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group A: active content and foreign elements (M5 spec section 12.2).
$m = C::MARKER;
$el = static fn (string $id, string $inner, ?string $element, string $reason = 'element'): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => $reason, 'line' => 2, 'element' => $element,
];

return [
    $el('A1', '<script>' . $m . '</script>', 'script'),
    $el('A2', '<p><script>' . $m . '</script></p>', 'script'),
    $el('A3', '<style>p{} /* ' . $m . ' */</style>', 'style'),
    $el('A4', '<iframe src="https://example.com/">' . $m . '</iframe>', 'iframe'),
    $el('A5', '<object data="x">' . $m . '</object>', 'object'),
    $el('A6', '<embed src="x" title="' . $m . '"/>', 'embed'),
    $el('A7', '<form>' . $m . '</form>', 'form'),
    $el('A8', '<input value="' . $m . '"/>', 'input'),
    $el('A9', '<button>' . $m . '</button>', 'button'),
    $el('A10', '<textarea>' . $m . '</textarea>', 'textarea'),
    $el('A11', '<select title="' . $m . '"></select>', 'select'),
    $el('A12', '<template><p>' . $m . '</p></template>', 'template'),
    $el('A13', '<noscript>' . $m . '</noscript>', 'noscript'),
    $el('A14', '<base href="https://example.com/' . $m . '"/>', 'base'),
    $el('A15', '<link rel="stylesheet" href="' . $m . '"/>', 'link'),
    $el('A16', '<meta http-equiv="refresh" content="0;' . $m . '"/>', 'meta'),
    $el('A17', '<svg><script>' . $m . '</script></svg>', 'svg'),
    $el('A18', '<svg xmlns="http://www.w3.org/2000/svg">' . $m . '</svg>', 'svg'),
    $el('A19', '<math><mtext>' . $m . '</mtext></math>', 'math'),
    // A20 (†): the prefixed name is checked before its namespace declaration, so the element rule refuses it.
    $el('A20', '<x:p xmlns:x="http://www.w3.org/1999/xhtml">' . $m . '</x:p>', 'x:p'),
    $el('A21', '<P>' . $m . '</P>', 'P'),
    $el('A22', '<Div data-tiffin-field="' . C::KEY . '"></Div>', 'Div'),
    [
        'id' => 'A23', 'text' => '<DIV data-tiffin-form="1"><p>' . $m . '</p></DIV>', 'verdict' => 'refuse',
        'reason' => 'root', 'line' => 1, 'element' => 'DIV',
    ],
    // A24: a name of 300 letters is no name that may be shown.
    $el('A24', '<p>' . $m . '<' . str_repeat('a', 300) . '>x</' . str_repeat('a', 300) . '></p>', null),
    // Extra hostile names, each refused by the element rule.
    $el('A25', '<p><span onclick="' . $m . '">x</span></p>', 'span'),
    $el('A26', '<frameset>' . $m . '</frameset>', 'frameset'),
    $el('A27', '<applet>' . $m . '</applet>', 'applet'),
    $el('A28', '<xmp>' . $m . '</xmp>', 'xmp'),
    $el('A29', '<plaintext>' . $m . '</plaintext>', 'plaintext'),
    $el('A30', '<audio src="x">' . $m . '</audio>', 'audio'),
    $el('A31', '<video src="x">' . $m . '</video>', 'video'),
    $el('A32', '<details open="">' . $m . '</details>', 'details'),
    $el('A33', '<p><img src="attachment:1" alt="' . $m . '"/></p>', 'img', 'misplaced'),
];
