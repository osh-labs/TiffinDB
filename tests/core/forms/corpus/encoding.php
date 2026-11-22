<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) TiffinDB contributors
 */

declare(strict_types=1);

use TiffinDB\Tests\Core\Forms\Corpus\Corpus as C;

// Group E: encoding and bytes (M5 spec section 12.2). Texts are built from byte escapes.
$m = C::MARKER;
$bom = "\xEF\xBB\xBF";
$form = C::f('<p>' . $m . '</p>');
// The form is ASCII, so each UTF-16 code unit is the byte and a zero byte.
$utf16 = static function (string $text, bool $littleEndian): string {
    $out = '';
    foreach (str_split($text) as $byte) {
        $out .= $littleEndian ? $byte . "\0" : "\0" . $byte;
    }

    return $out;
};
$encoding = static fn (string $id, string $text, ?int $line): array => [
    'id' => $id, 'text' => $text, 'verdict' => 'refuse', 'reason' => 'encoding', 'line' => $line,
];
$lines = static fn (string $break, string $third): string => '<div data-tiffin-form="1">' . $break . '<p>x</p>' . $break . $third . $break . '</div>' . $break;

return [
    ['id' => 'E1', 'text' => $bom . C::f('<p>x</p>'), 'verdict' => 'accept', 'canonical' => C::canonical('<p>x</p>')],
    // E2 (†): libxml itself skips a byte order mark at the start, so after the first is removed it would
    // accept the second. The parser refuses it before libxml runs, as text before the root (section 3.1 item 1).
    ['id' => 'E2', 'text' => $bom . $bom . $form, 'verdict' => 'refuse', 'reason' => 'text', 'line' => 1],
    $encoding('E3', "\xFF\xFE" . $utf16($form, true), 1),
    $encoding('E4', "\xFE\xFF" . $utf16($form, false), 1),
    $encoding('E5', C::f('<p>' . $m . " caf\xE9</p>"), 2),
    $encoding('E6', C::f('<p>' . $m . "\xC0\xAF</p>"), 2),
    $encoding('E7', C::f('<p>' . $m . "\xED\xA0\x80</p>"), 2),
    $encoding('E8', C::f('<p>' . $m . "\x80</p>"), 2),
    $encoding('E9', C::f('<p>' . $m . '</p>') . "\xE2\x82", 4),
    $encoding('E10', C::f('<p>' . $m . "\xF5\x80\x80\x80</p>"), 2),
    ['id' => 'E11', 'text' => C::f('<p>' . $m . "\0</p>"), 'verdict' => 'refuse', 'reason' => 'nul', 'line' => 2],
    ['id' => 'E12', 'text' => C::f(C::slot(' data-tiffin-label="' . $m . "\0\"")), 'verdict' => 'refuse', 'reason' => 'nul', 'line' => 2],
    [
        'id' => 'E13', 'text' => $lines("\r\n", '<script>' . $m . '</script>'), 'verdict' => 'refuse', 'reason' => 'element',
        'line' => 3, 'element' => 'script',
    ],
    [
        'id' => 'E14', 'text' => $lines("\r", '<script>' . $m . '</script>'), 'verdict' => 'refuse', 'reason' => 'element',
        'line' => 3, 'element' => 'script',
    ],
    // Line rules for each reason group with CR, LF, and CR LF texts (section 4.2).
    ['id' => 'E15', 'text' => $lines("\r", '<p>' . $m . '</b>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 3],
    ['id' => 'E16', 'text' => $lines("\r\n", '<p>' . $m . '</b>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 3],
    ['id' => 'E17', 'text' => $lines("\n", '<p>' . $m . '</b>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 3],
    $encoding('E18', $lines("\r", '<p>' . $m . "\xC0</p>"), 3),
    $encoding('E19', $lines("\r\n", '<p>' . $m . "\xC0</p>"), 3),
    ['id' => 'E20', 'text' => $lines("\r", '<p>' . $m . "\0</p>"), 'verdict' => 'refuse', 'reason' => 'nul', 'line' => 3],
    ['id' => 'E21', 'text' => $lines("\r\n", '<p>&#x202E;' . $m . '</p>'), 'verdict' => 'refuse', 'reason' => 'text-value', 'line' => 3],
    ['id' => 'E22', 'text' => $lines("\r", '<p>&#x202E;' . $m . '</p>'), 'verdict' => 'refuse', 'reason' => 'text-value', 'line' => 3],
    ['id' => 'E23', 'text' => $lines("\r", '<?pi ' . $m . '?>'), 'verdict' => 'refuse', 'reason' => 'processing-instruction', 'line' => 3],
    ['id' => 'E24', 'text' => $lines("\r\n", '<!DOCTYPE ' . $m . '>'), 'verdict' => 'refuse', 'reason' => 'declaration', 'line' => 3],
    // A byte order mark does not shift lines.
    [
        'id' => 'E25', 'text' => $bom . $lines("\n", '<script>' . $m . '</script>'), 'verdict' => 'refuse', 'reason' => 'element',
        'line' => 3, 'element' => 'script',
    ],
    ['id' => 'E26', 'text' => $bom . $lines("\n", '<p>' . $m . '</b>'), 'verdict' => 'refuse', 'reason' => 'malformed', 'line' => 3],
    // A mark anywhere but the start is U+FEFF, refused by the text rules.
    ['id' => 'E27', 'text' => C::f('<p>' . $m . $bom . '</p>'), 'verdict' => 'refuse', 'reason' => 'text-value', 'line' => 2],
    // A literal carriage return in text is stored as a line feed (CR LF as one).
    ['id' => 'E28', 'text' => C::f("<p>a\r\nb\rc</p>"), 'verdict' => 'accept', 'canonical' => C::canonical("<p>a\nb\nc</p>")],
    // Mixed line ends count each break once.
    [
        'id' => 'E29', 'text' => "<div data-tiffin-form=\"1\">\r\n<p>x</p>\r<hr/>\n<script>" . $m . "</script>\n</div>",
        'verdict' => 'refuse', 'reason' => 'element', 'line' => 4, 'element' => 'script',
    ],
];
