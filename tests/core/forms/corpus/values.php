<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group C: attribute values (M5 spec section 12.2).
$m = C::MARKER;
$k = C::KEY;
$label = static fn (string $value): string => C::slot(' data-tiffin-label="' . $value . '"');
$bad = static fn (string $id, string $inner, string $element, string $attribute, string $reason = 'attribute-value'): array => [
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'refuse', 'reason' => $reason, 'line' => 2,
    'element' => $element, 'attribute' => $attribute,
];
$good = static fn (string $id, string $inner, ?string $canonicalLine = null): array => array_filter([
    'id' => $id, 'text' => C::f($inner), 'verdict' => 'accept',
    'canonical' => $canonicalLine === null ? null : C::canonical($canonicalLine),
], static fn (mixed $v): bool => $v !== null);
$sixty = str_repeat('L', 59) . "\u{E9}";
$img = static fn (string $src): string => '<img src="' . $src . '" alt="' . $m . '"/>';

return [
    $good('C1', $label($sixty), '<div data-tiffin-field="' . $k . '" data-tiffin-label="' . $sixty . '"></div>'),
    $bad('C2', $label($sixty . 'x'), 'div', 'data-tiffin-label'),
    $good('C3', $label(' Title '), '<div data-tiffin-field="' . $k . '" data-tiffin-label="Title"></div>'),
    $bad('C4', $label(''), 'div', 'data-tiffin-label'),
    $bad('C5', $label($m . '&#x202E;'), 'div', 'data-tiffin-label'),
    $bad('C6', $label('a&#10;' . $m), 'div', 'data-tiffin-label'),
    $bad('C7', '<p data-tiffin-col="0">' . $m . '</p>', 'p', 'data-tiffin-col'),
    $bad('C8', '<p data-tiffin-col="13">' . $m . '</p>', 'p', 'data-tiffin-col'),
    $bad('C9', '<p data-tiffin-col="01">' . $m . '</p>', 'p', 'data-tiffin-col'),
    $bad('C10', '<p data-tiffin-col=" 1">' . $m . '</p>', 'p', 'data-tiffin-col'),
    $bad('C11', '<p data-tiffin-col="+1">' . $m . '</p>', 'p', 'data-tiffin-col'),
    $bad('C12', '<p data-tiffin-row="51">' . $m . '</p>', 'p', 'data-tiffin-row'),
    $bad('C13', '<p data-tiffin-span="FULL">' . $m . '</p>', 'p', 'data-tiffin-span'),
    $bad('C14', '<p data-tiffin-span="0">' . $m . '</p>', 'p', 'data-tiffin-span'),
    $good('C15', '<p data-tiffin-span="full">x</p>', '<p data-tiffin-span="full">x</p>'),
    $good('C16', '<p data-tiffin-span="12">x</p>', '<p data-tiffin-span="12">x</p>'),
    $good('C17', '<p data-tiffin-row="50">x</p>', '<p data-tiffin-row="50">x</p>'),
    $bad('C18', '<div data-tiffin-field="M4PZ7K2QSW3N" data-tiffin-label="' . $m . '"></div>', 'div', 'data-tiffin-field'),
    $bad('C19', '<div data-tiffin-field="m4pz7k2qsw3" data-tiffin-label="' . $m . '"></div>', 'div', 'data-tiffin-field'),
    $bad('C20', '<fieldset data-tiffin-section="Details"><legend>' . $m . '</legend></fieldset>', 'fieldset', 'data-tiffin-section'),
    $bad('C21', $img('attachment:0'), 'img', 'src', 'src'),
    $bad('C22', $img('attachment:01'), 'img', 'src', 'src'),
    $bad('C23', $img('attachment:9223372036854775808'), 'img', 'src', 'src'),
    $bad('C24', $img('https://example.com/a.png'), 'img', 'src', 'src'),
    $bad('C25', $img('data:image/png;base64,AAAA'), 'img', 'src', 'src'),
    $bad('C26', $img('Attachment:1'), 'img', 'src', 'src'),
    $bad('C27', $img('attachment: 1'), 'img', 'src', 'src'),
    $good('C28', '<img src="attachment:9223372036854775807" alt="x"/>', '<img src="attachment:9223372036854775807" alt="x"/>'),
    $good('C29', '<img src="attachment:3" alt=""/>', '<img src="attachment:3" alt=""/>'),
    $bad('C30', '<img src="attachment:3" alt="' . $m . str_repeat('a', 191) . '"/>', 'img', 'alt'),
    // Extra value rules: the column and row bounds, a role's length, labels of white space or control characters.
    $good('C31', '<hr data-tiffin-col="12" data-tiffin-row="1" data-tiffin-span="1"/>', '<hr data-tiffin-col="12" data-tiffin-row="1" data-tiffin-span="1"/>'),
    $bad('C32', '<p data-tiffin-row="0">' . $m . '</p>', 'p', 'data-tiffin-row'),
    $bad('C33', '<p data-tiffin-span="13">' . $m . '</p>', 'p', 'data-tiffin-span'),
    $bad('C34', '<fieldset data-tiffin-section="a' . str_repeat('b', 32) . '"><legend>' . $m . '</legend></fieldset>', 'fieldset', 'data-tiffin-section'),
    $bad('C35', $label('   '), 'div', 'data-tiffin-label'),
    $bad('C36', $label($m . '&#x9;b'), 'div', 'data-tiffin-label'),
    $bad('C37', '<img src="attachment:3" alt="' . $m . '&#x200B;"/>', 'img', 'alt'),
    $bad('C38', '<img src="attachment:3" alt="' . $m . '&#xD;b"/>', 'img', 'alt'),
    $good('C39', '<img src="attachment:3" alt="  A photo  "/>', '<img src="attachment:3" alt="A photo"/>'),
];
