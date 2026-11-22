<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;
use TiffinDB\Tests\Support\RepoFiles;

// Group S: structure (M5 spec section 12.2).
$m = C::MARKER;
$k = C::KEY;
$refuse = static fn (string $id, string $inner, string $reason, ?int $line = 2, ?string $element = null): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => $reason, 'line' => $line,
    ...($element === null ? [] : ['element' => $element]),
];

// S15: the example of `docs/formats/tiffin.md` section 12.2, read from the document.
$doc = RepoFiles::read('docs/formats/tiffin.md');
$from = (int) strpos($doc, '### 12.2 Example');
preg_match('/```html\n(.*?)```/s', substr($doc, $from, (int) strpos($doc, '### 12.3') - $from), $example);
$example = $example[1] ?? '';

// S14: every element and attribute of section 12.1 once.
$every = '<div data-tiffin-form="1">' . "\n"
    . '<h2 data-tiffin-col="1" data-tiffin-row="1" data-tiffin-span="full">Title</h2>' . "\n"
    . '<fieldset data-tiffin-section="details" data-tiffin-col="2" data-tiffin-row="2" data-tiffin-span="12">'
    . '<legend>Details</legend>'
    . '<div data-tiffin-field="' . $k . '" data-tiffin-label="Shown name" data-tiffin-span="6"></div>'
    . '<h3 data-tiffin-span="4">Sub</h3>'
    . '<p data-tiffin-span="8">a<br/><strong>b</strong><em>c</em><a href="https://example.com/">d</a></p>'
    . '<hr data-tiffin-col="3"/>'
    . '<img src="attachment:7" alt="A photo" data-tiffin-row="4"/>'
    . '</fieldset>' . "\n"
    . '</div>';

return [
    ['id' => 'S1', 'text' => '', 'verdict' => 'refuse', 'reason' => 'root', 'line' => null],
    ['id' => 'S2', 'text' => " \n\t\n ", 'verdict' => 'refuse', 'reason' => 'malformed'],
    [
        'id' => 'S3', 'text' => '<div data-tiffin-form="1"></div>' . "\n" . '<div data-tiffin-form="1"><p>' . $m . '</p></div>',
        'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 2,
    ],
    ['id' => 'S4', 'text' => '<div data-tiffin-form="2"><p>' . $m . '</p></div>', 'verdict' => 'refuse', 'reason' => 'format-version', 'line' => 1],
    ['id' => 'S5', 'text' => '<div data-tiffin-form="01"><p>' . $m . '</p></div>', 'verdict' => 'refuse', 'reason' => 'format-version', 'line' => 1],
    [
        'id' => 'S6', 'text' => '<div data-tiffin-form="1" data-tiffin-span="full"><p>' . $m . '</p></div>', 'verdict' => 'refuse',
        'reason' => 'root', 'line' => 1, 'element' => 'div', 'attribute' => 'data-tiffin-span',
    ],
    $refuse('S7', '<fieldset><fieldset><p>' . $m . '</p></fieldset></fieldset>', 'misplaced', 2, 'fieldset'),
    $refuse('S8', '<fieldset><p>' . $m . '</p><legend>x</legend></fieldset>', 'misplaced', 2, 'legend'),
    $refuse('S9', '<fieldset><legend>' . $m . '</legend><legend>y</legend></fieldset>', 'misplaced', 2, 'legend'),
    $refuse('S10', '<legend>' . $m . '</legend>', 'misplaced', 2, 'legend'),
    $refuse('S11', '<strong>' . $m . '</strong>', 'misplaced', 2, 'strong'),
    $refuse('S12', '<p><h2>' . $m . '</h2></p>', 'misplaced', 2, 'h2'),
    [
        'id' => 'S13', 'text' => C::f(C::s(C::slot()) . "\n" . C::s(C::slot(' data-tiffin-label="' . $m . '"'))), 'verdict' => 'refuse',
        'reason' => 'duplicate-field', 'line' => 3, 'element' => 'div', 'attribute' => 'data-tiffin-field',
    ],
    ['id' => 'S14', 'text' => $every, 'verdict' => 'accept'],
    ['id' => 'S15', 'text' => $example, 'verdict' => 'accept', 'canonical' => $example],
    ['id' => 'S16', 'text' => C::f('<fieldset></fieldset>'), 'verdict' => 'accept', 'canonical' => C::canonical('<fieldset>', '</fieldset>')],
    ['id' => 'S17', 'text' => C::f('<div data-tiffin-field="zzzzzzzzzzzz"></div>'), 'verdict' => 'accept'],
    // Extra: inline content in a section, a block in a heading, a link in a strong in a link, an end tag with nothing open.
    $refuse('S18', C::s('<br/>' . $m), 'misplaced', 2, 'br'),
    $refuse('S19', '<h2><p>' . $m . '</p></h2>', 'misplaced', 2, 'p'),
    $refuse('S20', '<p><a href="https://example.com/"><strong><a href="https://example.com/">' . $m . '</a></strong></a></p>', 'misplaced', 2, 'a'),
    $refuse('S21', '<p><em><fieldset>' . $m . '</fieldset></em></p>', 'misplaced', 2, 'fieldset'),
    ['id' => 'S22', 'text' => '<fieldset data-tiffin-form="1"><p>' . $m . '</p></fieldset>', 'verdict' => 'refuse', 'reason' => 'root', 'line' => 1, 'element' => 'fieldset'],
    ['id' => 'S23', 'text' => '<div><p>' . $m . '</p></div>', 'verdict' => 'refuse', 'reason' => 'root', 'line' => 1],
    $refuse('S24', '<img src="attachment:1" alt=""><p>' . $m . '</p></img>', 'content', 2, 'p'),
    $refuse('S25', C::slot() . "\n" . '<div data-tiffin-field="' . $k . '" data-tiffin-label="' . $m . '"/>', 'duplicate-field', 3, 'div'),
    ['id' => 'S26', 'text' => C::f(C::s('<p>a</p>') . C::s('')), 'verdict' => 'accept'],
];
